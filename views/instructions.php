<?php
/** Getting-started guide for the standalone CRM. In scope: $t. */
?>
<h2><?= $h($t('instr_title')) ?></h2>
<p class="lead"><?= $h($t('instr_intro')) ?></p>
<?php foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $s): ?>
  <div class="step"><h3><?= $h($t('instr_' . $s . '_t')) ?></h3><p><?= $t('instr_' . $s) ?></p></div>
<?php endforeach; ?>
<div class="step accent"><h3><?= $h($t('instr_manual_t')) ?></h3><p><?= $t('instr_manual') ?></p></div>
