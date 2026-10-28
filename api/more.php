<?php
// Loop actions: talk, membership, search, AI loop-spotting, guests, the posting hook, push subscriptions.
// Included by index.php inside its try block; $action and $method are already set.
declare(strict_types=1);
require_once __DIR__ . '/push.php';

switch ($action) {

  /* ---------- talk ---------- */
  case 'talk.list': {
    // Messages in one thread. With "after", only newer ones (the app polls every few seconds while a thread is open).
    $me = require_person();
    $thread = s_in('thread', 60);
    $acc = thread_access($thread, $me);
    $after = (int) (input()['after'] ?? 0);
    $rows = $after
      ? q('SELECT * FROM loop_talk WHERE thread = ? AND (created_at > ? OR edited_at > ?) ORDER BY created_at LIMIT 300', [$thread, $after, $after])->fetchAll()
      : array_reverse(q('SELECT * FROM loop_talk WHERE thread = ? ORDER BY created_at DESC LIMIT 150', [$thread])->fetchAll());
    q('REPLACE INTO loop_talk_reads (person_id, thread, read_at) VALUES (?,?,?)', [$me['id'], $thread, ms()]);
    $sugg = q("SELECT * FROM loop_suggestions WHERE thread = ? AND status = 'open' ORDER BY created_at DESC LIMIT 3", [$thread])->fetchAll();
    $members = $acc['kind'] === 'space' ? q('SELECT person_id FROM loop_space_members WHERE space_id = ?', [$acc['space']['id']])->fetchAll(PDO::FETCH_COLUMN) : [];
    $msgs = array_map('talk_row', $rows);
    $ff = files_for('talk', array_column($msgs, 'id'));
    foreach ($msgs as &$mm) $mm['files'] = $mm['kind'] === 'deleted' ? [] : ($ff[$mm['id']] ?? []);
    unset($mm);
    out(['ok' => true, 'messages' => $msgs, 'members' => $members, 'serverNow' => ms(),
         'suggestions' => array_map(fn($s) => ['id' => $s['id'], 'msgId' => $s['msg_id'], 'loop' => json_decode($s['data'], true)], $sugg)]);
  }

  case 'talk.send': {
    $me = require_person();
    $thread = s_in('thread', 60);
    $text = s_in('text', 4000);
    $fids = is_array(input()['fileIds'] ?? null) ? input()['fileIds'] : [];
    if ($text === '' && !$fids) fail('Write something first.');
    $acc = thread_access($thread, $me);
    $mentions = [];
    foreach (is_array(input()['mentions'] ?? null) ? input()['mentions'] : [] as $m) {
      $m = (string) $m;
      if ($m !== $me['id'] && preg_match('/^[A-Za-z0-9]{1,20}$/', $m) && q("SELECT 1 FROM loop_people WHERE id = ? AND active = 1 AND source IN ('s','r','m')", [$m])->fetchColumn()) $mentions[] = $m;
    }
    $mentions = array_slice(array_unique($mentions), 0, 20);
    $id = uid(); $t = ms();
    q('INSERT INTO loop_talk (id, thread, person_id, text, kind, mentions, created_at) VALUES (?,?,?,?,?,?,?)', [$id, $thread, $me['id'], $text, 'msg', $mentions ? implode(',', $mentions) : null, $t]);
    q('REPLACE INTO loop_talk_reads (person_id, thread, read_at) VALUES (?,?,?)', [$me['id'], $thread, $t]);
    attach_files($fids, $me, 'talk', $id, null, $thread);
    // Mentioning someone in a space brings them into it.
    if ($acc['kind'] === 'space' && $mentions) add_members($acc['space']['id'], $mentions);
    // Notifications: the other person in a one-to-one, and anyone mentioned.
    $where = $acc['kind'] === 'space' ? $acc['space']['name'] : '';
    if ($acc['kind'] === 'dm') push_to($acc['other'], $me['name'], $text !== '' ? $text : 'Sent an attachment', 'dm-' . $me['id'], '?t=' . rawurlencode($thread));
    foreach ($mentions as $m) push_to($m, $me['name'] . ' mentioned you in ' . $where, $text, 'm-' . $thread, '?t=' . rawurlencode($thread));
    $row = talk_row(q('SELECT * FROM loop_talk WHERE id = ?', [$id])->fetch());
    $row['files'] = files_for('talk', [$id])[$id] ?? [];
    out(['ok' => true, 'message' => $row]);
  }

  case 'talk.edit':
  case 'talk.delete': {
    $me = require_person();
    $m = q('SELECT * FROM loop_talk WHERE id = ?', [s_in('id', 20)])->fetch();
    if (!$m) fail('That message no longer exists.', 404);
    if ($m['person_id'] !== $me['id'] || $m['kind'] !== 'msg') fail('You can only change your own messages.', 403);
    if ($action === 'talk.delete') { q("UPDATE loop_talk SET kind = 'deleted', text = '', edited_at = ? WHERE id = ?", [ms(), $m['id']]); out(['ok' => true]); }
    $text = s_in('text', 4000);
    if ($text === '') fail('A message can’t be empty. Delete it instead.');
    q('UPDATE loop_talk SET text = ?, edited_at = ? WHERE id = ?', [$text, ms(), $m['id']]);
    out(['ok' => true]);
  }

  /* ---------- space membership (who sees a space's talk) ---------- */
  case 'spaces.join': {
    $me = require_person();
    $sp = q('SELECT * FROM loop_spaces WHERE id = ? AND archived = 0', [s_in('id', 20)])->fetch();
    if (!$sp) fail('That space no longer exists.', 404);
    if (!is_director($me)) fail('Spaces are by invitation. Ask one of its members to add you.', 403);
    add_members($sp['id'], [$me['id']]);
    out(['ok' => true]);
  }
  case 'spaces.leave': {
    $me = require_person();
    q('DELETE FROM loop_space_members WHERE space_id = ? AND person_id = ?', [s_in('id', 20), $me['id']]);
    out(['ok' => true]);
  }
  case 'spaces.addMember': {
    $me = require_person();
    $sp = q('SELECT * FROM loop_spaces WHERE id = ?', [s_in('id', 20)])->fetch();
    if (!$sp) fail('That space no longer exists.', 404);
    if (!can_see_space($sp['id'], $me)) fail('Only members of this space can add people.', 403);
    $pid = s_in('personId', 20);
    $p = q("SELECT * FROM loop_people WHERE id = ? AND active = 1 AND source IN ('s','r','m') AND email IS NOT NULL", [$pid])->fetch();
    if (!$p) fail('That person can’t use Loop yet.');
    add_members($sp['id'], [$pid]);
    post_system('s:' . $sp['id'], $me['id'], 'added ' . $p['name']);
    out(['ok' => true]);
  }

  /* ---------- search ---------- */
  case 'search': {
    $me = require_person();
    $qtext = s_in('q', 100);
    if (mb_strlen($qtext) < 2) out(['ok' => true, 'loops' => [], 'talk' => []]);
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qtext) . '%';
    // Loops I'm in, plus ledger entries of spaces I've been in a loop in (the same rule as sync).
    $mine = q("SELECT DISTINCT l.id FROM loop_items l LEFT JOIN loop_parts p ON p.loop_id = l.id AND p.person_id = ?
               WHERE l.author_id = ? OR p.person_id IS NOT NULL
               UNION SELECT l.id FROM loop_items l WHERE l.status = 'closed' AND l.type IN ('ask','decide') AND l.space_id IN (
                 SELECT DISTINCT l2.space_id FROM loop_items l2 LEFT JOIN loop_parts p2 ON p2.loop_id = l2.id AND p2.person_id = ?
                 WHERE l2.space_id IS NOT NULL AND (l2.author_id = ? OR p2.person_id IS NOT NULL))", [$me['id'], $me['id'], $me['id'], $me['id']])->fetchAll(PDO::FETCH_COLUMN);
    $loops = [];
    if ($mine) {
      $in = implode(',', array_fill(0, count($mine), '?'));
      $hits = q("SELECT DISTINCT l.id FROM loop_items l LEFT JOIN loop_notes n ON n.loop_id = l.id
                 WHERE l.id IN ($in) AND (l.title LIKE ? OR l.body LIKE ? OR l.outcome_text LIKE ? OR l.outcome_note LIKE ? OR n.text LIKE ?)
                 ORDER BY l.created_at DESC LIMIT 25", array_merge($mine, [$like, $like, $like, $like, $like]))->fetchAll(PDO::FETCH_COLUMN);
      $loops = load_loops($hits);
      usort($loops, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
    }
    // Talk in my spaces and my one-to-ones.
    $threads = array_column(my_threads($me), 'thread');
    $talk = [];
    if ($threads) {
      $in = implode(',', array_fill(0, count($threads), '?'));
      $talk = array_map('talk_row', q("SELECT * FROM loop_talk WHERE thread IN ($in) AND kind = 'msg' AND text LIKE ? ORDER BY created_at DESC LIMIT 30", array_merge($threads, [$like]))->fetchAll());
    }
    out(['ok' => true, 'loops' => $loops, 'talk' => $talk]);
  }

  /* ---------- AI: spot loops hiding in talk ---------- */
  case 'ai.spot': {
    $me = require_person();
    $key = (string) cfg('anthropic_key', '');
    if ($key === '') out(['ok' => true, 'suggestions' => [], 'off' => true]);
    $thread = s_in('thread', 60);
    $acc = thread_access($thread, $me);
    // Look at most once per thread every 3 minutes, and only when there are at least 4 new messages since the last look.
    $metaKey = 'spot_' . substr(hash('sha1', $thread), 0, 30);
    $lastAt = (int) (q('SELECT v FROM loop_meta WHERE k = ?', [$metaKey])->fetchColumn() ?: 0);
    $fresh = (int) q("SELECT COUNT(*) FROM loop_talk WHERE thread = ? AND kind = 'msg' AND created_at > ?", [$thread, $lastAt])->fetchColumn();
    if ($fresh < 4 || ms() - $lastAt < 180000) out(['ok' => true, 'suggestions' => [], 'skipped' => true]);
    q('REPLACE INTO loop_meta (k, v) VALUES (?, ?)', [$metaKey, (string) ms()]);

    $rows = array_reverse(q("SELECT * FROM loop_talk WHERE thread = ? AND kind = 'msg' ORDER BY created_at DESC LIMIT 40", [$thread])->fetchAll());
    $names = []; foreach (q("SELECT id, name FROM loop_people WHERE source IN ('s','r','m')")->fetchAll() as $p) $names[$p['id']] = $p['name'];
    $lines = [];
    foreach ($rows as $r) $lines[] = '[' . $r['id'] . '] ' . ($names[$r['person_id']] ?? 'Someone') . ' (' . $r['person_id'] . '): ' . mb_substr($r['text'], 0, 500);
    $existing = q("SELECT l.title FROM loop_items l JOIN loop_talk t ON t.loop_id = l.id WHERE t.thread = ?", [$thread])->fetchAll(PDO::FETCH_COLUMN);
    $already = q("SELECT data FROM loop_suggestions WHERE thread = ?", [$thread])->fetchAll(PDO::FETCH_COLUMN);
    $where = $acc['kind'] === 'space' ? 'the "' . $acc['space']['name'] . '" project space' : 'a one-to-one conversation';
    $prompt = "This is recent talk in $where on Loop, the internal messaging tool at " . org_about() . ".\n"
      . "Loop turns open items into typed loops: ask (a question someone must answer), decide (a choice between options that one person must make), do (a task with one owner).\n"
      . "Find at most 2 items in the talk that are still open and would get lost in chat: an unanswered question to a specific person, a decision being debated without a conclusion, or a task someone was asked to do. Ignore greetings, things already resolved, and anything already listed below.\n"
      . "Already loops or suggested:\n" . implode("\n", array_merge($existing, array_map(fn($d) => (string) (json_decode($d, true)['title'] ?? ''), $already))) . "\n\n"
      . "Talk (oldest first; [message id] name (person id): text):\n" . implode("\n", $lines) . "\n\n"
      . 'Reply with only a JSON array (empty if nothing qualifies): [{"type":"ask|decide|do","title":"one clear line","to":["person ids from the talk"],"options":["for decide only"],"msgId":"the message id it came from","why":"under 12 words"}]';
    $ch = curl_init((string) cfg('anthropic_url', 'https://api.anthropic.com/v1/messages'));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
      CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
      CURLOPT_POSTFIELDS => json_encode(['model' => cfg('anthropic_model', 'claude-haiku-5-5'), 'max_tokens' => 700, 'messages' => [['role' => 'user', 'content' => $prompt]]])]);
    $res = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($res === false || $status !== 200) { error_log("Loop AI spot failed: HTTP $status"); out(['ok' => true, 'suggestions' => []]); }
    $reply = ''; foreach ((json_decode((string) $res, true)['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $reply .= $c['text'];
    $a = strpos($reply, '['); $b = strrpos($reply, ']');
    $list = ($a !== false && $b !== false) ? json_decode(substr($reply, $a, $b - $a + 1), true) : [];
    $made = [];
    foreach (is_array($list) ? array_slice($list, 0, 2) : [] as $s) {
      if (!is_array($s) || !isset(LOOP_TYPES[$s['type'] ?? '']) || !in_array($s['type'], ['ask', 'decide', 'do'], true) || trim((string) ($s['title'] ?? '')) === '') continue;
      $to = array_values(array_filter(array_map('strval', is_array($s['to'] ?? null) ? $s['to'] : []), fn($id) => isset($names[$id])));
      $data = ['type' => $s['type'], 'title' => mb_substr(trim((string) $s['title']), 0, 200), 'to' => $to,
               'options' => $s['type'] === 'decide' && is_array($s['options'] ?? null) ? array_slice(array_map('strval', $s['options']), 0, 4) : [],
               'why' => mb_substr((string) ($s['why'] ?? ''), 0, 120)];
      $msgId = preg_match('/^[a-f0-9]{1,20}$/', (string) ($s['msgId'] ?? '')) ? (string) $s['msgId'] : null;
      $sid = uid();
      q('INSERT INTO loop_suggestions (id, thread, msg_id, data, created_at) VALUES (?,?,?,?,?)', [$sid, $thread, $msgId, json_encode($data, JSON_UNESCAPED_UNICODE), ms()]);
      $made[] = ['id' => $sid, 'msgId' => $msgId, 'loop' => $data];
    }
    out(['ok' => true, 'suggestions' => $made]);
  }

  case 'suggestions.update': {
    $me = require_person();
    $s = q('SELECT * FROM loop_suggestions WHERE id = ?', [s_in('id', 20)])->fetch();
    if (!$s) fail('That suggestion is gone.', 404);
    thread_access($s['thread'], $me);
    $status = s_in('status', 10) === 'used' ? 'used' : 'dismissed';
    q('UPDATE loop_suggestions SET status = ?, acted_by = ? WHERE id = ?', [$status, $me['id'], $s['id']]);
    out(['ok' => true]);
  }

  /* ---------- people outside the organisation ---------- */
  case 'people.guest': {
    // Add (or find) a person from outside by email. They can be put on a loop and answer it from a link; they can't sign in.
    $me = require_person();
    $email = clean_email(s_in('email', 190));
    $name = s_in('name', 120);
    if (!$email) fail('Enter a valid email address.');
    if ($staff = person_by_email($email)) out(['ok' => true, 'id' => $staff['id']]); // typed a colleague's email: just pick them
    $g = q("SELECT * FROM loop_people WHERE source = 'g' AND email = ?", [$email])->fetch();
    if ($g) {
      if ($name !== '' && $name !== $g['name']) q('UPDATE loop_people SET name = ? WHERE id = ?', [$name, $g['id']]);
      q('UPDATE loop_people SET active = 1 WHERE id = ?', [$g['id']]);
      out(['ok' => true, 'id' => $g['id']]);
    }
    if ($name === '') fail('Add their name so the loop shows who they are.');
    $sid = (int) q("SELECT COALESCE(MAX(source_id), 0) + 1 FROM loop_people WHERE source = 'g'")->fetchColumn();
    $id = 'g' . $sid;
    $domain = substr(strrchr($email, '@') ?: '', 1);
    q('INSERT INTO loop_people (id, source, source_id, email, name, title, grp, active, notify_email) VALUES (?,?,?,?,?,?,?,1,0)', [$id, 'g', $sid, $email, $name, 'Guest', $domain]);
    out(['ok' => true, 'id' => $id]);
  }

  /* ---------- the guest page (guest.html) ---------- */
  case 'guest.view':
  case 'guest.answer':
  case 'guest.choose':
  case 'guest.done':
  case 'guest.note': {
    $tok = s_in('t', 80);
    $link = strlen($tok) === 64 ? q('SELECT * FROM loop_guest_links WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $tok), ms()])->fetch() : null;
    if (!$link) fail('This link has expired or isn’t valid. Ask the sender to send it again.', 404);
    $g = q('SELECT * FROM loop_people WHERE id = ?', [$link['person_id']])->fetch();
    $l = q('SELECT * FROM loop_items WHERE id = ?', [$link['loop_id']])->fetch();
    $role = q('SELECT role FROM loop_parts WHERE loop_id = ? AND person_id = ?', [$l['id'] ?? '', $g['id'] ?? ''])->fetchColumn();
    if (!$g || !$l || !$role) fail('You’re no longer part of this loop.', 404);
    $t = ms();
    if ($action === 'guest.view') {
      q('UPDATE loop_parts SET seen_at = COALESCE(seen_at, ?) WHERE loop_id = ? AND person_id = ?', [$t, $l['id'], $g['id']]);
      $loop = load_loops([$l['id']])[0];
      $ids = array_merge([$loop['author']], array_keys((array) $loop['parts']), array_map(fn($r) => $r['by'], array_values((array) $loop['replies'])));
      $marks = implode(',', array_fill(0, count($ids), '?'));
      $people = [];
      foreach (q("SELECT id, name, title, grp, source FROM loop_people WHERE id IN ($marks)", $ids)->fetchAll() as $p) $people[$p['id']] = ['name' => $p['name'], 'title' => $p['source'] === 'g' ? 'Guest' : org_short()];
      out(['ok' => true, 'loop' => $loop, 'people' => $people, 'me' => ['id' => $g['id'], 'name' => $g['name'], 'role' => $role], 'org' => org_name(), 'orgShort' => org_short()]);
    }
    if ($l['status'] !== 'open') fail('This loop is already closed.', 409);
    if ($action === 'guest.note') {
      $text = s_in('text', 4000); if ($text === '') fail('Write something first.');
      q('INSERT INTO loop_notes (id, loop_id, person_id, text, kind, created_at) VALUES (?,?,?,?,?,?)', [uid(), $l['id'], $g['id'], $text, 'note', $t]);
    } elseif ($action === 'guest.answer') {
      if ($l['type'] !== 'ask' || $role !== 'responder') fail('Only the people asked can answer.', 403);
      $text = s_in('text', 4000); if ($text === '') fail('Write your answer first.');
      close_loop($l['id'], $g['id'], $text);
      q('INSERT INTO loop_notes (id, loop_id, person_id, text, kind, created_at) VALUES (?,?,?,?,?,?)', [uid(), $l['id'], $g['id'], $text, 'answer', $t]);
    } elseif ($action === 'guest.choose') {
      if ($l['type'] !== 'decide' || $role !== 'decider') fail('Only the decider can choose.', 403);
      $opt = q('SELECT * FROM loop_options WHERE id = ? AND loop_id = ?', [s_in('optionId', 20), $l['id']])->fetch();
      if (!$opt) fail('Pick one of the options.');
      close_loop($l['id'], $g['id'], $opt['label'], s_in('note', 4000), $opt['id']);
    } else {
      if ($l['type'] !== 'do' || $role !== 'owner') fail('Only the owner can mark this done.', 403);
      close_loop($l['id'], $g['id'], 'Done', s_in('note', 4000));
    }
    push_to($l['author_id'], $g['name'] . ' (guest) replied', $l['title'], 'loop-' . $l['id'], '?l=' . $l['id']);
    out(['ok' => true]);
  }

  /* ---------- other apps posting into Loop (server to server) ---------- */
  case 'hook': {
    // POST JSON {token, source, space, text, tell?: {title, to: [emails]}}. Server-to-server; see README.
    $tok = (string) cfg('hook_token', '');
    if ($tok === '' || !hash_equals($tok, s_in('token', 200))) fail('Bad token.', 403);
    migrate();
    $source = s_in('source', 40) ?: 'Portal';
    $sysId = 'x' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($source)) ?: 'portal', 0, 18);
    q("INSERT INTO loop_people (id, source, source_id, email, name, title, grp, active, notify_email) VALUES (?,?,?,NULL,?,?,?,0,0)
       ON DUPLICATE KEY UPDATE name = VALUES(name)", [$sysId, 'x', crc32($sysId) & 0x7fffffff, $source, 'Connected app', org_short()]);
    $spaceName = s_in('space', 80);
    $spaceId = null;
    if ($spaceName !== '') {
      $spaceId = q('SELECT id FROM loop_spaces WHERE (id = ? OR LOWER(name) = LOWER(?)) AND archived = 0 ORDER BY id = ? DESC LIMIT 1', [$spaceName, $spaceName, $spaceName])->fetchColumn() ?: null;
      if (!$spaceId) { $spaceId = uid(); q('INSERT INTO loop_spaces (id, name, kind, created_by, created_at) VALUES (?,?,?,?,?)', [$spaceId, $spaceName, 'team', $sysId, ms()]); }
    }
    $text = s_in('text', 2000);
    if ($spaceId && $text !== '') q('INSERT INTO loop_talk (id, thread, person_id, text, kind, created_at) VALUES (?,?,?,?,?,?)', [uid(), 's:' . $spaceId, $sysId, $text, 'msg', ms()]);
    $loopId = null;
    $tell = input()['tell'] ?? null;
    if (is_array($tell) && trim((string) ($tell['title'] ?? '')) !== '') {
      $to = [];
      foreach (is_array($tell['to'] ?? null) ? $tell['to'] : [] as $e) { $p = person_by_email(clean_email((string) $e)); if ($p && $p['email']) $to[] = $p['id']; }
      if ($to) {
        $loopId = uid(); $t = ms();
        q('INSERT INTO loop_items (id, type, title, body, author_id, space_id, deliver_at, created_at) VALUES (?,?,?,?,?,?,?,?)',
          [$loopId, 'tell', mb_substr(trim((string) $tell['title']), 0, 200), mb_substr((string) ($tell['body'] ?? ''), 0, 8000), $sysId, $spaceId, next_window($t, true), $t]);
        foreach (array_unique($to) as $pid) q('INSERT INTO loop_parts (loop_id, person_id, role) VALUES (?,?,?)', [$loopId, $pid, 'recipient']);
        if ($spaceId) add_members($spaceId, $to);
      }
    }
    out(['ok' => true, 'spaceId' => $spaceId, 'loopId' => $loopId]);
  }

  /* ---------- phone notifications ---------- */
  case 'push.subscribe': {
    $me = require_person();
    $sub = input()['sub'] ?? null;
    $endpoint = is_array($sub) ? (string) ($sub['endpoint'] ?? '') : '';
    $p256 = is_array($sub) ? (string) ($sub['keys']['p256dh'] ?? '') : '';
    $auth = is_array($sub) ? (string) ($sub['keys']['auth'] ?? '') : '';
    if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000 || strlen(b64u_dec($p256)) !== 65 || strlen(b64u_dec($auth)) !== 16) fail('That notification setup isn’t valid.');
    q('INSERT INTO loop_push_subs (person_id, endpoint_hash, endpoint, p256dh, auth, created_at) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), p256dh = VALUES(p256dh), auth = VALUES(auth)', [$me['id'], hash('sha256', $endpoint), $endpoint, $p256, $auth, ms()]);
    $sent = push_to($me['id'], 'Loop notifications are on', 'You’ll hear about “now” loops, nudges, messages and mentions here.', 'welcome');
    out(['ok' => true, 'tested' => $sent > 0]);
  }
  case 'push.unsubscribe': {
    $me = require_person();
    $endpoint = s_in('endpoint', 1000);
    if ($endpoint) q('DELETE FROM loop_push_subs WHERE person_id = ? AND endpoint_hash = ?', [$me['id'], hash('sha256', $endpoint)]);
    else q('DELETE FROM loop_push_subs WHERE person_id = ?', [$me['id']]);
    out(['ok' => true]);
  }

  default: fail('Unknown action.', 404);
}
