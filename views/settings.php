<?php
/**
 * Settings — company, channels (WhatsApp/SMTP), reminder cadences, the pipeline &
 * stage editor, logistics, the public form/webhook URLs, and the OPTIONAL Bitrix24
 * sync (off by default). In scope: $t, $h, $cfg, $pdo.
 */
// Build the shown URLs from the live domain in use (falls back to app.base_url).
$base = \Glue\Config::appBaseUrl() ?: rtrim((string)$cfg('app.base_url', ''), '/');
$is = $h($cfg('app.intake_secret', ''));
$os = $h($cfg('bitrix.outbound_secret', ''));
$syncOn = (bool)$cfg('bitrix.sync_enabled', false);
$urls = [
    'url_request'   => "$base/request.php",
    'url_form'      => "$base/webhooks/form-intake.php?secret=$is",
    'url_appt'      => "$base/webhooks/appointment-intake.php?secret=$is",
    'url_bitrix_ev' => "$base/webhooks/bitrix-event.php?secret=$os",
];
$pipelines = \Glue\Crm\Pipelines::all();
?>
<h2><?= $h($t('setup_title')) ?></h2>
<p class="muted"><?= $h($t('setup_intro')) ?></p>

<div class="card">
  <h3><?= $h($t('urls_title')) ?></h3>
  <p class="muted small"><?= $h($t('urls_intro')) ?></p>
  <?php foreach ($urls as $k => $u): ?>
    <label class="fld"><span><?= $h($t($k)) ?></span>
      <input readonly value="<?= $h($u) ?>" onclick="this.select()"></label>
  <?php endforeach; ?>
</div>

<form method="post" class="card">
  <input type="hidden" name="do" value="save_settings">
  <h3><?= $h($t('sec_general')) ?></h3>
  <div class="row">
    <?php
    fld($h, 'app.company_name', $t('f_company_name'), $cfg('app.company_name', ''));
    fld($h, 'crm.currency', $t('f_currency'), $cfg('crm.currency', 'EUR'));
    fld($h, 'app.default_lang', $t('f_default_lang'), $cfg('app.default_lang', 'it'));
    fld($h, 'app.timezone', $t('f_tz'), $cfg('app.timezone', 'Europe/Rome'));
    fld($h, 'app.default_country_code', $t('f_country_code'), $cfg('app.default_country_code', '39'), $t('f_country_code_h'));
    ?>
  </div>
  <div class="row">
    <?php
    fld($h, 'app.base_url', $t('f_base_url'), $cfg('app.base_url', ''), $t('f_base_url_h'));
    fld($h, 'app.intake_secret', $t('f_intake'), $cfg('app.intake_secret', ''), $t('f_intake_h'));
    ?>
  </div>

  <h3><?= $h($t('sec_whatsapp')) ?></h3>
  <?php fld($h, 'textmebot.api_key', $t('f_tmb_key'), $cfg('textmebot.api_key'), $t('f_tmb_key_h')); ?>

  <h3><?= $h($t('sec_mail')) ?></h3>
  <div class="row">
    <?php
    fld($h, 'mail.from_name', $t('f_from_name'), $cfg('mail.from_name'));
    fld($h, 'mail.from_email', $t('f_from_email'), $cfg('mail.from_email'));
    ?>
  </div>
  <p class="muted small"><?= $h($t('f_smtp_h')) ?></p>
  <div class="row">
    <?php
    fld($h, 'mail.smtp.host', $t('f_smtp_host'), $cfg('mail.smtp.host'));
    fld($h, 'mail.smtp.port', $t('f_smtp_port'), $cfg('mail.smtp.port'));
    fld($h, 'mail.smtp.user', $t('f_smtp_user'), $cfg('mail.smtp.user'));
    fld($h, 'mail.smtp.pass', $t('f_smtp_pass'), $cfg('mail.smtp.pass'));
    fld($h, 'mail.smtp.secure', $t('f_smtp_secure'), $cfg('mail.smtp.secure'));
    ?>
  </div>

  <h3><?= $h($t('sec_cadences')) ?></h3>
  <div class="row">
    <?php
    fld($h, 'reminders.lead_inactivity_hours', $t('f_lead_inact'), $cfg('reminders.lead_inactivity_hours', 3));
    fld($h, 'reminders.deal_inactivity_hours', $t('f_deal_inact'), $cfg('reminders.deal_inactivity_hours', 3));
    fld($h, 'reminders.sign_after_sent_days', $t('f_sign_after'), $cfg('reminders.sign_after_sent_days', 15));
    fld($h, 'reminders.sign_overdue_every_days', $t('f_sign_every'), $cfg('reminders.sign_overdue_every_days', 3));
    fld($h, 'reminders.sign_overdue_max_days', $t('f_sign_max'), $cfg('reminders.sign_overdue_max_days', 15));
    fld($h, 'crm.deal_quote_stage', $t('f_quote_stage'), $cfg('crm.deal_quote_stage', 'QUOTE'));
    ?>
  </div>

  <h3><?= $h($t('sec_logistics')) ?></h3>
  <div class="row">
    <?php
    fld($h, 'logistics.email', $t('f_log_email'), $cfg('logistics.email'));
    fld($h, 'logistics.phone', $t('f_log_phone'), $cfg('logistics.phone'));
    ?>
  </div>

  <h3><?= $h($t('sec_bitrix')) ?> <span class="pill"><?= $h($t('optional')) ?></span></h3>
  <p class="muted small"><?= $h($t('sec_bitrix_h')) ?></p>
  <label class="fld" style="display:flex;flex-direction:row;align-items:center;gap:10px">
    <input type="checkbox" name="bitrix.sync_enabled" value="true" style="width:auto" <?= $syncOn ? 'checked' : '' ?>>
    <span style="margin:0"><?= $h($t('f_sync_enable')) ?></span>
  </label>
  <div class="row">
    <?php
    fld($h, 'bitrix.base_url', $t('f_bitrix_url'), $cfg('bitrix.base_url'), $t('f_bitrix_url_h'));
    fld($h, 'bitrix.outbound_secret', $t('f_outbound'), $cfg('bitrix.outbound_secret'), $t('f_outbound_h'));
    ?>
  </div>

  <button class="btn"><?= $h($t('save')) ?></button>
</form>

<div class="card">
  <h3><?= $h($t('sec_pipelines')) ?></h3>
  <p class="muted small"><?= $h($t('sec_pipelines_h')) ?></p>
  <?php foreach ($pipelines as $p): $stages = \Glue\Crm\Pipelines::stages((int)$p['id']); ?>
    <div style="margin-bottom:18px">
      <h3 style="margin-bottom:8px"><?= $h($p['name']) ?> <span class="muted small">(<?= $h($p['entity_type']) ?>)</span></h3>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <?php foreach ($stages as $s): $locked = $s['is_first'] || $s['is_won'] || $s['is_lost']; ?>
          <span class="pill" style="display:inline-flex;align-items:center;gap:7px">
            <span class="dotc" style="background:<?= $h($s['color'] ?: '#5b6cff') ?>"></span><?= $h($s['name']) ?>
            <span class="muted">(<?= $h($s['code']) ?>)</span>
            <?php if (!$locked): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('?')">
                <input type="hidden" name="do" value="stage_delete"><input type="hidden" name="id" value="<?= $h($s['id']) ?>">
                <button style="background:none;border:none;color:var(--red);cursor:pointer;padding:0;font-weight:700">×</button></form>
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
      <form method="post" class="inline"><input type="hidden" name="do" value="stage_add">
        <input type="hidden" name="pipeline_id" value="<?= $h($p['id']) ?>">
        <input name="code" placeholder="<?= $h($t('stage_code')) ?>" required style="max-width:130px">
        <input name="name" placeholder="<?= $h($t('stage_name')) ?>" required style="max-width:170px">
        <button class="btn tiny ghost"><?= $h($t('stage_add')) ?></button></form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3><?= $h($t('test_title')) ?></h3>
  <form method="post" class="inline"><input type="hidden" name="do" value="test_whatsapp">
    <input name="to" placeholder="<?= $h($t('test_phone_ph')) ?>" required><button class="btn ghost"><?= $h($t('test_wa')) ?></button></form>
  <form method="post" class="inline"><input type="hidden" name="do" value="test_email">
    <input name="to" placeholder="<?= $h($t('test_email_ph')) ?>" required><button class="btn ghost"><?= $h($t('test_email')) ?></button></form>
  <?php if ($syncOn): ?>
  <form method="post" class="inline"><input type="hidden" name="do" value="test_bitrix">
    <button class="btn ghost"><?= $h($t('test_bitrix')) ?></button></form>
  <?php endif; ?>
</div>
