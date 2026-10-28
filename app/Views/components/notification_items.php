<?php if(!$items): ?><p class="notification-empty">No notifications.</p><?php else: ?><ul class="notification-list"><?php foreach($items as $item): ?>
<li class="<?= $item['read_at']?'notification-read':'notification-unread' ?>"><a href="<?= esc($item['link'],'attr') ?>"><?= esc($item['title']) ?></a><p><?= esc($item['body']) ?></p><small><?= esc($item['created_at']) ?> UTC<?= $item['read_at']?' · Read':' · Unread' ?></small>
<?php if(!$item['read_at']): ?><form method="post" action="/notifications/<?= esc($item['ref'],'attr') ?>/read"><?= csrf_field() ?><button class="secondary" type="submit">Mark as read</button></form><?php endif ?></li>
<?php endforeach ?></ul><?php endif ?>
