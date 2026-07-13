<?php
/**
 * Agents / sellers — accounts with the profile (name/email/phone/title) that the
 * customer receives when a lead is assigned (req #3). Create, edit profile, reset
 * password, enable/disable, delete. In scope: $t, $h, $uid.
 */
$users = \Glue\Auth::all();
$meId = (int)($_SESSION['glue_user']['id'] ?? 0);
?>
<h2><?= $h($t('nav_agents')) ?></h2>

<form method="post" class="card">
  <input type="hidden" name="do" value="create_user">
  <h3><?= $h($t('u_add')) ?></h3>
  <div class="row">
    <label class="fld"><span><?= $h($t('u_username')) ?></span><input name="username" required></label>
    <label class="fld"><span><?= $h($t('u_password')) ?></span><input name="password" required></label>
    <label class="fld"><span><?= $h($t('u_role')) ?></span>
      <select name="role"><option value="agent"><?= $h($t('role_agent')) ?></option><option value="tech"><?= $h($t('role_tech')) ?></option><option value="admin"><?= $h($t('role_admin')) ?></option></select></label>
  </div>
  <div class="row">
    <label class="fld"><span><?= $h($t('u_fullname')) ?></span><input name="full_name"></label>
    <label class="fld"><span><?= $h($t('u_title')) ?></span><input name="title" placeholder="<?= $h($t('u_title_ph')) ?>"></label>
    <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone"></label>
    <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
  </div>
  <button class="btn"><?= $h($t('u_create')) ?></button>
</form>

<?php foreach ($users as $u): $id = (int)$u['id']; $nm = trim((string)($u['full_name'] ?? '')) ?: $u['username']; ?>
  <details class="drawer card" style="padding:0;margin-bottom:8px">
    <summary class="dw-sum">
      <?= avatar($h, $nm) ?>
      <span class="dw-info"><b><?= $h($nm) ?></b>
        <?php if ($id === $meId): ?> <span class="muted small"><?= $h($t('u_you')) ?></span><?php endif; ?>
        <span class="muted small"> · @<?= $h($u['username']) ?> · <?= $h($u['title'] ?? '') ?></span></span>
      <span class="pill"><?= $h($u['role']) ?></span>
      <span class="badge <?= $u['active'] ? 'ok' : 'no' ?>"><span class="dot"></span><?= $u['active'] ? $h($t('u_active')) : $h($t('u_disabled')) ?></span>
    </summary>
    <div style="padding:6px 18px 18px;border-top:1px solid var(--line)">
      <?php
      // #6 — this agent's assigned leads with current stage/status, so an admin can
      // click an agent and see all their leads at a glance.
      $agLeads = \Glue\Crm\Leads::all(500, $id);
      $agOpen = 0; $agConv = 0;
      foreach ($agLeads as $al) { if ($al['status'] === 'converted') { $agConv++; } elseif ($al['status'] === 'open') { $agOpen++; } }
      ?>
      <details class="drawer" style="margin-bottom:14px">
        <summary class="btn ghost tiny">
          <?= svg('leads') ?> <?= $h($t('agent_leads_h')) ?> ·
          <?= count($agLeads) ?> <span class="muted">(<?= $agOpen ?> <?= $h($t('agent_leads_open')) ?>, <?= $agConv ?> <?= $h($t('agent_leads_conv')) ?>)</span>
        </summary>
        <?php if (!$agLeads): ?>
          <div class="empty" style="margin-top:10px"><?= $h($t('agent_leads_none')) ?></div>
        <?php else: ?>
        <table style="margin-top:10px"><thead><tr>
          <th><?= $h($t('f_name')) ?></th><th><?= $h($t('f_phone')) ?></th>
          <th><?= $h($t('nav_leads')) ?></th><th><?= $h($t('th_status')) ?></th>
        </tr></thead><tbody>
          <?php foreach ($agLeads as $al): ?>
          <tr>
            <td><?= $h($al['customer_name'] ?: ('#' . $al['id'])) ?></td>
            <td class="small muted"><?= $h($al['customer_phone'] ?? '') ?></td>
            <td><span class="pill"><?= $h(stage_label($t, $al['stage_code'], \Glue\Crm\Pipelines::label('lead', $al['stage_code']))) ?></span></td>
            <td><?= pill($h, $al['status'], $t) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </details>
      <form method="post"><input type="hidden" name="do" value="update_profile"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="row">
          <label class="fld"><span><?= $h($t('u_fullname')) ?></span><input name="full_name" value="<?= $h($u['full_name'] ?? '') ?>"></label>
          <label class="fld"><span><?= $h($t('u_title')) ?></span><input name="title" value="<?= $h($u['title'] ?? '') ?>"></label>
          <label class="fld"><span><?= $h($t('u_role')) ?></span>
            <select name="role"><option value="agent"<?= $u['role'] === 'agent' ? ' selected' : '' ?>><?= $h($t('role_agent')) ?></option>
              <option value="tech"<?= $u['role'] === 'tech' ? ' selected' : '' ?>><?= $h($t('role_tech')) ?></option>
              <option value="admin"<?= $u['role'] === 'admin' ? ' selected' : '' ?>><?= $h($t('role_admin')) ?></option></select></label>
        </div>
        <div class="row">
          <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" value="<?= $h($u['phone'] ?? '') ?>"></label>
          <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email" value="<?= $h($u['email'] ?? '') ?>"></label>
        </div>
        <button class="btn tiny"><?= $h($t('save')) ?></button>
      </form>
      <div style="margin-top:12px">
        <form method="post" class="inline"><input type="hidden" name="do" value="set_password"><input type="hidden" name="id" value="<?= $id ?>">
          <input name="password" placeholder="<?= $h($t('u_new_pw')) ?>" required><button class="btn tiny ghost"><?= $h($t('u_set')) ?></button></form>
        <form method="post" class="inline"><input type="hidden" name="do" value="toggle_user"><input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="active" value="<?= $u['active'] ? '0' : '1' ?>">
          <button class="btn tiny ghost"><?= $u['active'] ? $h($t('u_disable')) : $h($t('u_enable')) ?></button></form>
        <?php if ($id !== $meId):
          // Escape for JS single-quote string first (handles apostrophes in the
          // name and in the IT copy), then for the HTML attribute.
          $confirmMsg = $h(addslashes(str_replace('{name}', $nm, $t('u_delete_confirm')))); ?>
        <form method="post" class="inline" onsubmit="return confirm('<?= $confirmMsg ?>')">
          <input type="hidden" name="do" value="delete_user"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn tiny danger"><?= $h($t('u_delete')) ?></button></form>
        <?php endif; ?>
      </div>
    </div>
  </details>
<?php endforeach; ?>

<form method="post" class="card">
  <input type="hidden" name="do" value="change_my_password">
  <h3><?= $h($t('change_pw_title')) ?></h3>
  <label class="fld"><span><?= $h($t('u_new_pw')) ?></span><input name="password" required></label>
  <button class="btn"><?= $h($t('save')) ?></button>
</form>
