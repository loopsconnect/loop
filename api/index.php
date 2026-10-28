<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$action = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Every write must carry the app's header: a page on another site cannot add it.
if ($method === 'POST' && ($_SERVER['HTTP_X_LOOP'] ?? '') !== '1' && $action !== 'hook') fail('Bad request.', 400);
if ($method !== 'POST' && $action !== 'sync' && $action !== 'info') fail('Use POST.', 405);

try {
  require_once __DIR__ . '/files.php';
  if (storage_cfg()) files_table(); // before any transaction: CREATE TABLE would end it
  if (str_starts_with($action, 'files.')) files_action($action);
  if (preg_match('/^(talk\.|search$|ai\.spot$|suggestions\.|people\.guest$|guest\.|hook$|push\.|spaces\.(join|leave|addMember)$)/', $action)) {
    require __DIR__ . '/more.php';
  }
  if (preg_match('/^people\.(list|add|update|import|invite)$/', $action)) require __DIR__ . '/people.php';
  require_once __DIR__ . '/push.php';
  switch ($action) {

    /* ---------- before sign-in: who runs this Loop ---------- */
    case 'info': {
      require_once __DIR__ . '/sso.php';
      out(['ok' => true, 'org' => org_name(), 'orgShort' => org_short(), 'peopleSource' => people_source(), 'version' => LOOP_VERSION, 'sso' => sso_enabled()]);
    }

    /* ---------- sign-in: a 6-digit code to the person's email ---------- */
    case 'auth.request': {
      $email = clean_email(s_in('email', 190));
      if (!$email) fail('Enter a valid email address.');
      sync_staff();
      $p = person_by_email($email);
      if (!$p) { sync_staff(true); $p = person_by_email($email); }
      if (!$p) fail('This email isn’t on ' . people_home() . '. ' . ask_admin_to_add(), 403);
      $recent = (int) q('SELECT COUNT(*) FROM loop_otp WHERE email = ? AND created_at > ?', [$email, ms() - 3600000])->fetchColumn();
      if ($recent >= 6) fail('Too many codes requested. Try again in an hour.', 429);
      $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      q('INSERT INTO loop_otp (email, code_hash, created_at, expires_at) VALUES (?,?,?,?)', [$email, hash('sha256', $email . '|' . $code), ms(), ms() + 600000]);
      $sent = send_mail($email, "Your Loop code: $code", "Hi {$p['name']},\n\nYour " . org_short() . " Loop sign-in code is $code\n\nIt works for 10 minutes. If you didn't ask for it, ignore this email.\n\n" . cfg('app_url'),
        mail_html(['hi' => first_name($p['name']), 'lead' => 'Your Loop sign-in code:', 'big' => $code, 'foot' => 'It works for 10 minutes. If you didn’t ask for it, ignore this email.']));
      if (!$sent) fail('We couldn’t send the email. Ask the admin to check Loop’s mail settings.', 500);
      out(['ok' => true]);
    }

    case 'auth.verify': {
      $email = clean_email(s_in('email', 190));
      $code = preg_replace('/\D/', '', s_in('code', 12));
      if (!$email || strlen($code) !== 6) fail('Enter the 6-digit code from the email.');
      $row = q('SELECT * FROM loop_otp WHERE email = ? AND expires_at > ? ORDER BY id DESC LIMIT 1', [$email, ms()])->fetch();
      if (!$row) fail('That code has expired. Ask for a new one.');
      if ((int) $row['attempts'] >= 5) fail('Too many wrong tries. Ask for a new code.', 429);
      if (!hash_equals($row['code_hash'], hash('sha256', $email . '|' . $code))) {
        q('UPDATE loop_otp SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        fail('That code isn’t right. Check the email and try again.');
      }
      q('DELETE FROM loop_otp WHERE email = ?', [$email]);
      $p = person_by_email($email);
      if (!$p) fail('This email is no longer on ' . people_home() . '.', 403);
      start_session($p['id']);
      out(['ok' => true]);
    }

    case 'auth.logout': { end_session(); out(['ok' => true]); }

    case 'me.update': {
      $me = require_person();
      $notify = input()['notifyEmail'] ?? null;
      if ($notify !== null) q('UPDATE loop_people SET notify_email = ? WHERE id = ?', [$notify ? 1 : 0, $me['id']]);
      out(['ok' => true]);
    }

    /* ---------- everything the app shows ---------- */
    case 'sync': {
      $me = require_person();
      sync_staff();
      $people = array_map('public_person', q('SELECT * FROM loop_people ORDER BY name')->fetchAll());
      $spaces = q('SELECT id, name, kind, created_by AS createdBy, created_at AS createdAt, archived FROM loop_spaces ORDER BY name')->fetchAll();
      $mem = [];
      foreach (q('SELECT space_id, person_id FROM loop_space_members')->fetchAll() as $m) $mem[$m['space_id']][] = $m['person_id'];
      // Only spaces I'm a member of; directors see them all.
      $vis = visible_space_ids($me);
      if ($vis !== null) { $vis = array_flip($vis); $spaces = array_values(array_filter($spaces, fn($s) => isset($vis[$s['id']]))); }
      foreach ($spaces as &$s) { $s['createdAt'] = (int) $s['createdAt']; $s['archived'] = (bool) (int) $s['archived']; $s['members'] = $mem[$s['id']] ?? []; }
      unset($s);
      $since = ms() - 90 * 86400000;
      // Loops I'm in (open, or closed in the last 90 days), plus the recorded answers and decisions
      // of every space I have been in a loop in (that space's ledger).
      $ids = q("SELECT DISTINCT l.id FROM loop_items l LEFT JOIN loop_parts p ON p.loop_id = l.id AND p.person_id = ?
                WHERE (l.author_id = ? OR p.person_id IS NOT NULL) AND (l.status = 'open' OR l.closed_at > ?)
                UNION
                SELECT l.id FROM loop_items l WHERE l.status = 'closed' AND l.type IN ('ask','decide') AND l.space_id IN (
                  SELECT DISTINCT l2.space_id FROM loop_items l2 LEFT JOIN loop_parts p2 ON p2.loop_id = l2.id AND p2.person_id = ?
                  WHERE l2.space_id IS NOT NULL AND (l2.author_id = ? OR p2.person_id IS NOT NULL))",
               [$me['id'], $me['id'], $since, $me['id'], $me['id']])->fetchAll(PDO::FETCH_COLUMN);
      if (is_director($me)) // directors see every space's ledger
        $ids = array_values(array_unique(array_merge($ids, q("SELECT id FROM loop_items WHERE status = 'closed' AND type IN ('ask','decide') AND space_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN))));
      $wk = week_key(ms());
      out([
        'org' => ['name' => org_name(), 'short' => org_short(), 'peopleSource' => people_source(), 'version' => LOOP_VERSION],
        'me' => ['id' => $me['id'], 'name' => $me['name'], 'email' => $me['email'], 'title' => $me['title'], 'group' => $me['grp'],
                 'notifyEmail' => (bool) $me['notify_email'], 'isAdmin' => is_admin($me), 'isDirector' => is_director($me),
                 'nowUsed' => $me['now_week'] === $wk ? (int) $me['now_used'] : 0],
        'settings' => ['nowPerWeek' => (int) cfg('now_per_week', 0), 'windows' => cfg('windows'), 'skipDays' => cfg('skip_days', [0]),
                       'timezone' => cfg('timezone'), 'ai' => (string) cfg('anthropic_key', '') !== '', 'vapidPublic' => vapid_public_b64u(), 'filesMaxMb' => storage_cfg() ? storage_cfg()['max_mb'] : 0, 'talkDays' => talk_days()],
        'users' => $people, 'spaces' => $spaces, 'loops' => load_loops($ids), 'threads' => my_threads($me), 'serverNow' => ms(),
      ]);
    }

    /* ---------- spaces ---------- */
    case 'spaces.create': {
      $me = require_person();
      $name = s_in('name', 80);
      $kind = in_array(s_in('kind', 20), ['project', 'team', 'place', 'family'], true) ? s_in('kind', 20) : 'project';
      if ($name === '') fail('Give the space a name.');
      $id = uid();
      q('INSERT INTO loop_spaces (id, name, kind, created_by, created_at) VALUES (?,?,?,?,?)', [$id, $name, $kind, $me['id'], ms()]);
      add_members($id, [$me['id']]);
      out(['ok' => true, 'id' => $id]);
    }

    case 'spaces.update': {
      // Whoever created the space, or a Loop admin, can rename it, change its kind, or archive it.
      $me = require_person();
      $sp = q('SELECT * FROM loop_spaces WHERE id = ?', [s_in('id', 20)])->fetch();
      if (!$sp) fail('That space no longer exists.', 404);
      if ($sp['created_by'] !== $me['id'] && !is_admin($me)) fail('Only the person who created this space, or an admin, can change it.', 403);
      $name = s_in('name', 80);
      if ($name === '') fail('Give the space a name.');
      $kind = in_array(s_in('kind', 20), ['project', 'team', 'place', 'family'], true) ? s_in('kind', 20) : $sp['kind'];
      $archived = array_key_exists('archived', input()) ? (!empty(input()['archived']) ? 1 : 0) : (int) $sp['archived'];
      $dupe = q('SELECT 1 FROM loop_spaces WHERE id <> ? AND archived = 0 AND LOWER(name) = LOWER(?)', [$sp['id'], $name])->fetchColumn();
      if ($dupe && !$archived) fail('Another space is already called “' . $name . '”.');
      q('UPDATE loop_spaces SET name = ?, kind = ?, archived = ? WHERE id = ?', [$name, $kind, $archived, $sp['id']]);
      out(['ok' => true]);
    }

    /* ---------- loops ---------- */
    case 'loops.create': {
      $me = require_person();
      $in = input();
      $type = s_in('type', 10);
      if (!isset(LOOP_TYPES[$type])) fail('Pick a loop type.');
      $title = s_in('title', 200);
      if ($title === '') fail('Add a headline or question.');
      $to = array_values(array_unique(array_filter(array_map('strval', is_array($in['to'] ?? null) ? $in['to'] : []))));
      $to = array_values(array_filter($to, fn($id) => $id !== $me['id']));
      if (!$to) fail('Choose who this loop is for.');
      if (isset(LOOP_SINGLE[$type]) && count($to) > 1) fail('A ' . ucfirst($type) . ' loop goes to one person.');
      $marks = implode(',', array_fill(0, count($to), '?'));
      $found = q("SELECT id, name, email FROM loop_people WHERE active = 1 AND id IN ($marks)", $to)->fetchAll();
      if (count($found) !== count($to)) fail('One of those people is no longer on ' . people_home() . '.');
      foreach ($found as $f) if (!$f['email']) fail($f['name'] . ' can’t use Loop yet: they have no email address.');
      $opts = [];
      if ($type === 'decide') {
        foreach (is_array($in['options'] ?? null) ? $in['options'] : [] as $o) { $o = is_string($o) ? trim(mb_substr($o, 0, 140)) : ''; if ($o !== '') $opts[] = $o; }
        $opts = array_slice($opts, 0, 4);
        if (count($opts) < 2) fail('A Decide loop needs at least two options.');
      }
      $due = s_in('due', 10);
      $due = ($type !== 'tell' && $type !== 'share' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) ? $due : null;
      $urgent = !empty($in['urgent']);
      $limit = (int) cfg('now_per_week', 0);
      $wk = week_key(ms());
      $used = $me['now_week'] === $wk ? (int) $me['now_used'] : 0;
      if ($urgent && $limit > 0 && $used >= $limit) fail('No “now” sends left this week.');

      db()->beginTransaction();
      $spaceId = s_in('spaceId', 20) ?: null;
      $newSpace = s_in('newSpace', 80);
      if ($newSpace !== '') {
        $spaceId = uid();
        q('INSERT INTO loop_spaces (id, name, kind, created_by, created_at) VALUES (?,?,?,?,?)', [$spaceId, $newSpace, 'project', $me['id'], ms()]);
      } elseif ($spaceId && !q('SELECT 1 FROM loop_spaces WHERE id = ? AND archived = 0', [$spaceId])->fetchColumn()) {
        $spaceId = null;
      } elseif ($spaceId && !can_see_space($spaceId, $me)) {
        db()->rollBack();
        fail('You’re not in that space. Ask one of its members to add you.', 403);
      }
      $t = ms();
      $deliver = $urgent ? $t : next_window($t, $type === 'tell' || $type === 'share');
      $id = uid();
      q('INSERT INTO loop_items (id, type, title, body, author_id, space_id, due, urgent, deliver_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$id, $type, $title, s_in('body', 8000), $me['id'], $spaceId, $due, $urgent ? 1 : 0, $deliver, $t]);
      foreach ($to as $pid) q('INSERT INTO loop_parts (loop_id, person_id, role) VALUES (?,?,?)', [$id, $pid, LOOP_TYPES[$type]]);
      foreach ($opts as $i => $label) q('INSERT INTO loop_options (id, loop_id, label, pos) VALUES (?,?,?,?)', [uid(), $id, $label, $i]);
      if ($urgent) q('UPDATE loop_people SET now_week = ?, now_used = ? WHERE id = ?', [$wk, $used + 1, $me['id']]);
      $staffTo = array_values(array_filter($to, fn($pid) => $pid[0] !== 'g'));
      if ($spaceId) add_members($spaceId, array_merge([$me['id']], $staffTo));
      // A loop made from a talk message: link them and say so in the thread.
      $fromTalk = s_in('fromTalk', 20);
      if ($fromTalk) {
        $msg = q('SELECT * FROM loop_talk WHERE id = ?', [$fromTalk])->fetch();
        if ($msg) {
          thread_access($msg['thread'], $me);
          q('UPDATE loop_talk SET loop_id = ? WHERE id = ?', [$id, $msg['id']]);
          post_system($msg['thread'], $me['id'], 'turned this into ' . ($type === 'ask' ? 'an Ask' : 'a ' . ucfirst($type)) . ': ' . $title, $id);
        }
      }
      attach_files(is_array($in['fileIds'] ?? null) ? $in['fileIds'] : [], $me, 'loop', $id, $id, null);
      $sugg = s_in('suggestionId', 20);
      if ($sugg) q("UPDATE loop_suggestions SET status = 'used', acted_by = ? WHERE id = ?", [$me['id'], $sugg]);
      db()->commit();
      send_guest_links($id, array_values(array_filter($to, fn($pid) => $pid[0] === 'g')), $me, $type, $title);
      if ($urgent) foreach ($staffTo as $pid) push_to($pid, $me['name'] . ' · ' . ucfirst($type) . ' now', $title, 'loop-' . $id, '?l=' . $id);
      out(['ok' => true, 'id' => $id, 'deliverAt' => $deliver]);
    }

    case 'loops.seen': {
      $me = require_person();
      $ids = array_slice(array_filter(array_map('strval', is_array(input()['ids'] ?? null) ? input()['ids'] : [])), 0, 200);
      $t = ms();
      foreach ($ids as $id) {
        $n = q('UPDATE loop_parts p JOIN loop_items l ON l.id = p.loop_id SET p.seen_at = ? WHERE p.loop_id = ? AND p.person_id = ? AND p.seen_at IS NULL AND l.deliver_at <= ?', [$t, $id, $me['id'], $t])->rowCount();
        if ($n) {
          // A Tell closes once every recipient has seen it.
          $l = q('SELECT type, status FROM loop_items WHERE id = ?', [$id])->fetch();
          if ($l && $l['type'] === 'tell' && $l['status'] === 'open' && !q('SELECT 1 FROM loop_parts WHERE loop_id = ? AND seen_at IS NULL', [$id])->fetchColumn()) {
            q("UPDATE loop_items SET status = 'closed', closed_at = ?, outcome_text = 'Seen by everyone', outcome_by = ?, outcome_at = ? WHERE id = ? AND status = 'open'", [$t, $me['id'], $t, $id]);
          }
        }
      }
      out(['ok' => true]);
    }

    case 'loops.note': {
      $me = require_person();
      $l = loop_for(s_in('id', 20), $me);
      $text = s_in('text', 4000);
      $fids = is_array(input()['fileIds'] ?? null) ? input()['fileIds'] : [];
      if ($text === '' && !$fids) fail('Write something first.');
      $nid = uid();
      q('INSERT INTO loop_notes (id, loop_id, person_id, text, kind, created_at) VALUES (?,?,?,?,?,?)', [$nid, $l['id'], $me['id'], $text, 'note', ms()]);
      attach_files($fids, $me, 'note', $nid, $l['id'], null);
      out(['ok' => true]);
    }

    case 'loops.answer': {
      $me = require_person();
      $l = loop_for(s_in('id', 20), $me); require_open($l);
      if ($l['type'] !== 'ask' || $l['_role'] !== 'responder') fail('Only the people asked can answer.', 403);
      $text = s_in('text', 4000);
      if ($text === '') fail('Write your answer first.');
      db()->beginTransaction();
      close_loop($l['id'], $me['id'], $text);
      $nid = uid();
      q('INSERT INTO loop_notes (id, loop_id, person_id, text, kind, created_at) VALUES (?,?,?,?,?,?)', [$nid, $l['id'], $me['id'], $text, 'answer', ms()]);
      attach_files(is_array(input()['fileIds'] ?? null) ? input()['fileIds'] : [], $me, 'note', $nid, $l['id'], null);
      db()->commit();
      out(['ok' => true]);
    }

    case 'loops.choose': {
      $me = require_person();
      $l = loop_for(s_in('id', 20), $me); require_open($l);
      if ($l['type'] !== 'decide' || $l['_role'] !== 'decider') fail('Only the decider can choose.', 403);
      $opt = q('SELECT * FROM loop_options WHERE id = ? AND loop_id = ?', [s_in('optionId', 20), $l['id']])->fetch();
      if (!$opt) fail('Pick one of the options.');
      close_loop($l['id'], $me['id'], $opt['label'], s_in('note', 4000), $opt['id']);
      attach_files(is_array(input()['fileIds'] ?? null) ? input()['fileIds'] : [], $me, 'outcome', $l['id'], $l['id'], null);
      out(['ok' => true]);
    }

    case 'loops.done': {
      $me = require_person();
      $l = loop_for(s_in('id', 20), $me); require_open($l);
      if ($l['type'] !== 'do' || $l['_role'] !== 'owner') fail('Only the owner can mark this done.', 403);
      close_loop($l['id'], $me['id'], 'Done', s_in('note', 4000));
      attach_files(is_array(input()['fileIds'] ?? null) ? input()['fileIds'] : [], $me, 'outcome', $l['id'], $l['id'], null);
      out(['ok' => true]);
    }

    case 'loops.nudge': {
      $me = require_person();
      $l = loop_for(s_in('id', 20), $me); require_open($l);
      if (!$l['_author']) fail('Only the sender can nudge.', 403);
      if ($l['nudged_at']) fail('Already nudged once.', 409);
      q('UPDATE loop_items SET nudged_at = ?, deliver_at = LEAST(deliver_at, ?) WHERE id = ?', [ms(), ms(), $l['id']]);
      foreach (q('SELECT person_id FROM loop_parts WHERE loop_id = ?', [$l['id']])->fetchAll(PDO::FETCH_COLUMN) as $pid)
        if ($pid[0] !== 'g') push_to($pid, $me['name'] . ' nudged you', $l['title'], 'loop-' . $l['id'], '?l=' . $l['id']);
      out(['ok' => true]);
    }

    case 'loops.sendnow': {
      $me = require_person();
      $l = loop_for(s_in('id', 20), $me); require_open($l);
      if (!$l['_author']) fail('Only the sender can do that.', 403);
      $limit = (int) cfg('now_per_week', 0);
      $wk = week_key(ms());
      $used = $me['now_week'] === $wk ? (int) $me['now_used'] : 0;
      if ($limit > 0 && $used >= $limit) fail('No “now” sends left this week.');
      q('UPDATE loop_items SET deliver_at = ?, urgent = 1 WHERE id = ?', [ms(), $l['id']]);
      q('UPDATE loop_people SET now_week = ?, now_used = ? WHERE id = ?', [$wk, $used + 1, $me['id']]);
      foreach (q('SELECT person_id FROM loop_parts WHERE loop_id = ?', [$l['id']])->fetchAll(PDO::FETCH_COLUMN) as $pid)
        if ($pid[0] !== 'g') push_to($pid, $me['name'] . ' · ' . ucfirst($l['type']) . ' now', $l['title'], 'loop-' . $l['id'], '?l=' . $l['id']);
      out(['ok' => true]);
    }

    case 'loops.withdraw': {
      $me = require_person();
      $l = loop_for(s_in('id', 20), $me); require_open($l);
      if (!$l['_author']) fail('Only the sender can withdraw.', 403);
      q("UPDATE loop_items SET status = 'withdrawn', closed_at = ? WHERE id = ?", [ms(), $l['id']]);
      out(['ok' => true]);
    }

    /* ---------- editing ---------- */
    case 'loops.edit': {
      // The sender can change an open loop. Everyone in it sees a line saying what changed,
      // and anyone who had already read it gets it back marked as edited.
      $me = require_person();
      $in = input();
      $l = loop_for(s_in('id', 20), $me); require_open($l);
      if (!$l['_author']) fail('Only the sender can edit this loop.', 403);
      $changes = []; $content = false; $t = ms();

      $title = s_in('title', 200);
      if ($title === '') fail('Add a headline or question.');
      if ($title !== $l['title']) { $changes[] = ($l['type'] === 'ask' || $l['type'] === 'decide' ? 'Changed the question to “' : 'Changed the headline to “') . $title . '”'; $content = true; }
      $body = s_in('body', 8000);
      if ($body !== (string) $l['body']) { $changes[] = $body === '' ? 'Removed the details' : 'Updated the details'; $content = true; }
      $due = null;
      if ($l['type'] !== 'tell' && $l['type'] !== 'share') {
        $d = s_in('due', 10);
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        if ($due !== $l['due']) { $changes[] = $due ? 'Moved the deadline to ' . (new DateTimeImmutable($due))->format('D j M') : 'Removed the deadline'; $content = true; }
      }

      // Decide options: keep ids of options that stay, so nothing points at a missing option.
      $newOpts = null;
      if ($l['type'] === 'decide') {
        $cur = q('SELECT id, label FROM loop_options WHERE loop_id = ? ORDER BY pos', [$l['id']])->fetchAll();
        $curIds = array_column($cur, 'label', 'id');
        $newOpts = [];
        foreach (is_array($in['options'] ?? null) ? $in['options'] : [] as $o) {
          $label = is_array($o) && is_string($o['label'] ?? null) ? trim(mb_substr($o['label'], 0, 140)) : '';
          if ($label === '') continue;
          $oid = is_array($o) && is_string($o['id'] ?? null) && isset($curIds[$o['id']]) ? $o['id'] : null;
          $newOpts[] = ['id' => $oid, 'label' => $label];
        }
        $newOpts = array_slice($newOpts, 0, 4);
        if (count($newOpts) < 2) fail('A Decide loop needs at least two options.');
        $before = array_map(fn($o) => $o['label'], $cur);
        $after = array_map(fn($o) => $o['label'], $newOpts);
        if ($before !== $after) { $changes[] = 'Changed the options to: ' . implode(' / ', $after); $content = true; }
        else $newOpts = null;
      }

      // People
      $to = array_values(array_unique(array_filter(array_map('strval', is_array($in['to'] ?? null) ? $in['to'] : []))));
      $to = array_values(array_filter($to, fn($id) => $id !== $me['id']));
      if (!$to) fail('Choose who this loop is for.');
      if (isset(LOOP_SINGLE[$l['type']]) && count($to) > 1) fail('A ' . ucfirst($l['type']) . ' loop goes to one person.');
      $curTo = q('SELECT person_id FROM loop_parts WHERE loop_id = ?', [$l['id']])->fetchAll(PDO::FETCH_COLUMN);
      $add = array_values(array_diff($to, $curTo));
      $remove = array_values(array_diff($curTo, $to));
      $nameOf = [];
      if ($add || $remove) {
        $ids = array_merge($add, $remove);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (q("SELECT id, name, email, active FROM loop_people WHERE id IN ($marks)", $ids)->fetchAll() as $p) $nameOf[$p['id']] = $p;
        foreach ($add as $id) {
          if (empty($nameOf[$id]) || !$nameOf[$id]['active']) fail('One of those people is no longer on ' . people_home() . '.');
          if (!$nameOf[$id]['email']) fail($nameOf[$id]['name'] . ' can’t use Loop yet: they have no email address.');
        }
        if ($add) $changes[] = 'Added ' . implode(', ', array_map(fn($id) => $nameOf[$id]['name'], $add));
        if ($remove) $changes[] = 'Removed ' . implode(', ', array_map(fn($id) => $nameOf[$id]['name'] ?? 'someone', $remove));
      }

      if (!$changes) out(['ok' => true, 'changed' => false]);

      db()->beginTransaction();
      q('UPDATE loop_items SET title = ?, body = ?, due = ?, edited_at = ? WHERE id = ?', [$title, $body, $due, $t, $l['id']]);
      if ($newOpts !== null) {
        $keep = array_filter(array_column($newOpts, 'id'));
        $all = q('SELECT id FROM loop_options WHERE loop_id = ?', [$l['id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_diff($all, $keep) as $gone) q('DELETE FROM loop_options WHERE id = ?', [$gone]);
        foreach ($newOpts as $i => $o) {
          if ($o['id']) q('UPDATE loop_options SET label = ?, pos = ? WHERE id = ?', [$o['label'], $i, $o['id']]);
          else q('INSERT INTO loop_options (id, loop_id, label, pos) VALUES (?,?,?,?)', [uid(), $l['id'], $o['label'], $i]);
        }
      }
      foreach ($remove as $id) q('DELETE FROM loop_parts WHERE loop_id = ? AND person_id = ?', [$l['id'], $id]);
      foreach ($add as $id) q('INSERT INTO loop_parts (loop_id, person_id, role) VALUES (?,?,?)', [$l['id'], $id, LOOP_TYPES[$l['type']]]);
      if ($content) q('UPDATE loop_parts SET seen_at = NULL WHERE loop_id = ?', [$l['id']]);
      q('INSERT INTO loop_notes (id, loop_id, person_id, text, kind, created_at) VALUES (?,?,?,?,?,?)', [uid(), $l['id'], $me['id'], implode("\n", $changes), 'edit', $t]);
      if ($l['space_id']) add_members($l['space_id'], array_values(array_filter($add, fn($pid) => $pid[0] !== 'g')));
      db()->commit();
      send_guest_links($l['id'], array_values(array_filter($add, fn($pid) => $pid[0] === 'g')), $me, $l['type'], $title);
      out(['ok' => true, 'changed' => true]);
    }

    case 'notes.edit': {
      $me = require_person();
      $n = q('SELECT * FROM loop_notes WHERE id = ?', [s_in('noteId', 20)])->fetch();
      if (!$n) fail('That note no longer exists.', 404);
      if ($n['person_id'] !== $me['id'] || $n['kind'] !== 'note') fail('You can only edit your own notes.', 403);
      $l = loop_for($n['loop_id'], $me); require_open($l);
      $text = s_in('text', 4000);
      if ($text === '') fail('A note can’t be empty.');
      if ($text !== $n['text']) q('UPDATE loop_notes SET text = ?, edited_at = ? WHERE id = ?', [$text, ms(), $n['id']]);
      out(['ok' => true]);
    }

    /* ---------- AI drafting ---------- */
    case 'ai.draft': {
      $me = require_person();
      $key = (string) cfg('anthropic_key', '');
      if ($key === '') fail('AI drafting is off.', 501);
      $text = s_in('text', 2000);
      if ($text === '') fail('Say or type what you need first.');
      $hour = (int) q('SELECT COUNT(*) FROM loop_ai_calls WHERE person_id = ? AND at > ?', [$me['id'], ms() - 3600000])->fetchColumn();
      if ($hour >= (int) cfg('ai_per_hour', 40)) fail('Too many drafts this hour. Fill it in yourself for now.', 429);
      q('INSERT INTO loop_ai_calls (person_id, at) VALUES (?,?)', [$me['id'], ms()]);

      $people = [];
      foreach (q("SELECT id, name, title, grp FROM loop_people WHERE active = 1 AND email IS NOT NULL AND id <> ? ORDER BY name", [$me['id']])->fetchAll() as $p)
        $people[] = $p['id'] . ': ' . $p['name'] . ($p['title'] || $p['grp'] ? ' (' . trim($p['title'] . ', ' . $p['grp'], ', ') . ')' : '');
      $spaces = []; foreach (q('SELECT id, name FROM loop_spaces WHERE archived = 0')->fetchAll() as $s) if (can_see_space($s['id'], $me)) $spaces[] = $s['id'] . ': ' . $s['name'];
      $now = new DateTimeImmutable('now', new DateTimeZone(cfg('timezone', 'Asia/Kolkata')));
      $prompt = "You turn a spoken request into a structured \"loop\" for Loop, the internal messaging tool at " . org_about() . ".\n"
        . "Loop types: tell (FYI, no reply needed), ask (needs an answer), decide (someone must choose between options), do (a task with one owner), share (a personal moment, no obligation).\n"
        . 'Today is ' . $now->format('l Y-m-d') . '. The speaker is ' . $me['name'] . ".\n"
        . "People (id: name (title, group)). Names may be spoken as first names or nicknames; pick the closest match, and leave \"to\" empty if unsure:\n" . ($people ? implode("\n", $people) : '(none)') . "\n"
        . "Spaces (id: name):\n" . ($spaces ? implode("\n", $spaces) : '(none yet)') . "\n"
        . "Spoken text:\n\"\"\"" . $text . "\"\"\"\n"
        . 'Reply with only JSON of this shape: {"type":"tell|ask|decide|do|share","title":"one clear line; a question for ask and decide, an instruction for do","body":"extra detail from the speech or empty string","to":["ids of the people meant, from the list only"],"spaceId":"matching space id or null","newSpace":"project name mentioned that is not in spaces, else null","due":"YYYY-MM-DD or null","options":["2 to 4 short options, decide only, else empty"]}';

      $ch = curl_init((string) cfg('anthropic_url', 'https://api.anthropic.com/v1/messages'));
      curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => cfg('anthropic_model', 'claude-haiku-5-5'), 'max_tokens' => 800, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
      ]);
      $res = curl_exec($ch);
      $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($res === false || $status !== 200) { error_log("Loop AI draft failed: HTTP $status " . substr((string) $res, 0, 300)); fail('Couldn’t draft that right now. Fill it in yourself.', 502); }
      $body = json_decode((string) $res, true);
      $reply = '';
      foreach ($body['content'] ?? [] as $c) if (($c['type'] ?? '') === 'text') $reply .= $c['text'];
      $a = strpos($reply, '{'); $b = strrpos($reply, '}');
      $draft = ($a !== false && $b !== false) ? json_decode(substr($reply, $a, $b - $a + 1), true) : null;
      if (!is_array($draft)) fail('Couldn’t draft that. Try rewording, or fill it in yourself.', 502);
      out(['ok' => true, 'draft' => $draft]);
    }

    default: fail('Unknown action.', 404);
  }
} catch (Throwable $e) {
  try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {}
  error_log('Loop error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  $m = $e->getMessage();
  if ($e instanceof PDOException && preg_match('/\b(1044|1045|1049|2002|2005)\b|Access denied|Unknown database|getaddrinfo/', $m))
    fail('Loop can’t reach the database. Check db_host, db_name, db_user and db_pass in api/config.php.', 500);
  if ($e instanceof PDOException && preg_match("/loop_\w+' doesn't exist/", $m))
    fail('Loop’s tables are missing. Import api/schema.sql into the database in phpMyAdmin.', 500);
  fail('Something went wrong on the server. Try again.', 500);
}
