<?php
// Emails each person a short note when loops reach them (and, on the FHD Portal, keeps people in step with Resources).
// Run every 15 minutes, e.g. hPanel > Advanced > Cron Jobs:
//   /usr/local/bin/php /home/<user>/public_html/loop/api/cron.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/files.php';
migrate();

// Uploads that were started but never sent with a message (closed app, cancelled) are removed after a day.
if (storage_cfg()) {
  files_table();
  foreach (q("SELECT id, obj_key FROM loop_files WHERE ref_id IS NULL AND created_at < ?", [ms() - 86400000])->fetchAll() as $f) {
    store_delete($f['obj_key']);
    q('DELETE FROM loop_files WHERE id = ?', [$f['id']]);
  }
  // Talk attachments past their date (and not kept) leave the store; the message shows "expired".
  foreach (q("SELECT id, obj_key FROM loop_files WHERE status = 'ready' AND expires_at IS NOT NULL AND expires_at < ?", [ms()])->fetchAll() as $f) {
    store_delete($f['obj_key']);
    q("UPDATE loop_files SET status = 'expired' WHERE id = ?", [$f['id']]);
  }
}

sync_staff(true);
$t = ms();
$names = [];
foreach (q('SELECT id, name FROM loop_people')->fetchAll() as $p) $names[$p['id']] = $p['name'];
$labels = ['tell' => 'Tell', 'ask' => 'Ask', 'decide' => 'Decide', 'do' => 'Do', 'share' => 'Share'];

foreach (q("SELECT p.* FROM loop_people p WHERE p.active = 1 AND p.source IN ('s','r','m') AND p.email IS NOT NULL
            AND (p.notify_email = 1 OR EXISTS (SELECT 1 FROM loop_push_subs s WHERE s.person_id = p.id))")->fetchAll() as $u) {
  $since = (int) $u['last_notified'];
  // Loops that reached this person since the last note, still open and unseen.
  $rows = q("SELECT l.id, l.type, l.title, l.author_id, l.urgent, l.nudged_at FROM loop_items l JOIN loop_parts p ON p.loop_id = l.id
             WHERE p.person_id = ? AND p.seen_at IS NULL AND l.status = 'open' AND l.deliver_at <= ?
               AND (l.deliver_at > ? OR (l.nudged_at IS NOT NULL AND l.nudged_at > ?))
             ORDER BY l.urgent DESC, l.deliver_at", [$u['id'], $t, $since, $since])->fetchAll();
  q('UPDATE loop_people SET last_notified = ? WHERE id = ?', [$t, $u['id']]);
  if (!$rows) continue;

  $action = array_filter($rows, fn($r) => !in_array($r['type'], ['tell', 'share'], true));
  $subject = count($action)
    ? count($action) . (count($action) === 1 ? ' thing needs' : ' things need') . ' you in Loop'
    : count($rows) . ' new in your Loop digest';
  $lines = [];
  foreach ($rows as $r) {
    $flag = $r['nudged_at'] ? ' (nudged)' : ($r['urgent'] ? ' (now)' : '');
    $lines[] = '• ' . $labels[$r['type']] . ': ' . $r['title'] . ' — from ' . ($names[$r['author_id']] ?? 'someone') . $flag;
  }
  if ((int) $u['notify_email'] === 1) send_mail($u['email'], $subject, implode("\n", $lines) . "\n\nOpen Loop: " . cfg('app_url') . "\n\nTo stop these emails, turn them off in Loop under You.",
    mail_html(['body' => trim(implode("\n", $lines)), 'button' => 'Open Loop', 'url' => cfg('app_url'), 'foot' => 'To stop these emails, turn them off in Loop under You.']));
  push_to($u['id'], $subject, count($rows) === 1 ? $rows[0]['title'] : implode(' · ', array_map(fn($r) => $r['title'], array_slice($rows, 0, 3))), 'window');
}
