<?php
/**
 * Message templates — edit the WhatsApp text and email (subject + HTML) for every
 * automatic reminder/notification, per language. Stored as overrides in settings;
 * a blank field (or one left equal to the default) falls back to the shipped copy.
 * In scope: $t, $h, $lang.
 */
use Glue\Reminder\Templates;

$rules = Templates::ruleKeys();

// Placeholders the engine fills in at send time. Shown as a quick legend.
$placeholders = ['{name}', '{company}', '{agent_name}', '{agent_phone}', '{agent_email}',
    '{when}', '{deadline}', '{link}', '{code}', '{minutes}', '{id}', '{subject}',
    '{customer_name}', '{customer_phone}', '{customer_email}', '{username}', '{password}'];
?>
<h2><?= $h($t('tpl_title')) ?></h2>
<p class="muted small" style="max-width:760px"><?= $h($t('tpl_intro')) ?></p>

<div class="warn" style="max-width:760px">
  <b><?= $h($t('tpl_ph_title')) ?></b>
  <div style="margin-top:7px;display:flex;flex-wrap:wrap;gap:6px">
    <?php foreach ($placeholders as $p): ?>
      <code style="background:rgba(255,255,255,.07);padding:2px 7px;border-radius:6px;font-size:12px"><?= $h($p) ?></code>
    <?php endforeach; ?>
  </div>
  <div class="muted small" style="margin-top:9px"><?= $h($t('tpl_lang_note')) ?>
    <b><?= strtoupper($h($lang)) ?></b>.</div>
</div>

<form method="post" class="card">
  <input type="hidden" name="do" value="save_templates">
  <input type="hidden" name="tpl_lang" value="<?= $h($lang) ?>">

  <?php foreach ($rules as $rk):
      $waVal = Templates::overrideText('wa', $rk, $lang) ?: Templates::defaultText('wa', $rk, $lang);
      $esVal = Templates::overrideText('es', $rk, $lang) ?: Templates::defaultText('es', $rk, $lang);
      $ehVal = Templates::overrideText('eh', $rk, $lang) ?: Templates::defaultText('eh', $rk, $lang);
      $custom = Templates::overrideText('wa', $rk, $lang) !== ''
             || Templates::overrideText('es', $rk, $lang) !== ''
             || Templates::overrideText('eh', $rk, $lang) !== '';
  ?>
    <details class="drawer" style="border:1px solid var(--line);border-radius:10px;padding:0;margin-bottom:10px">
      <summary style="display:flex;align-items:center;gap:10px;padding:13px 16px;cursor:pointer">
        <b style="flex:1"><?= $h(code_label($t, 'rk_', $rk)) ?>
          <span class="muted small">(<?= $h($rk) ?>)</span></b>
        <?php if ($custom): ?><span class="pill pill-sent"><?= $h($t('tpl_customized')) ?></span><?php endif; ?>
      </summary>
      <div style="padding:6px 16px 18px;border-top:1px solid var(--line)">
        <label class="fld"><span><?= $h($t('tpl_wa')) ?></span>
          <textarea name="tpl_wa_<?= $h($rk) ?>" rows="3"><?= $h($waVal) ?></textarea></label>
        <label class="fld"><span><?= $h($t('tpl_email_subject')) ?></span>
          <input name="tpl_es_<?= $h($rk) ?>" value="<?= $h($esVal) ?>"></label>
        <label class="fld"><span><?= $h($t('tpl_email_html')) ?></span>
          <textarea name="tpl_eh_<?= $h($rk) ?>" rows="4"><?= $h($ehVal) ?></textarea></label>
      </div>
    </details>
  <?php endforeach; ?>

  <button class="btn" style="margin-top:6px"><?= $h($t('save')) ?></button>
  <p class="muted small" style="margin-top:8px"><?= $h($t('tpl_reset_note')) ?></p>
</form>
