<?php
declare(strict_types=1);

/**
 * Shared dashboard chrome: the icon set and the stylesheet. Lives here rather
 * than inside dashboard.php because the PARTNER area (public/partner.php) is the
 * same application wearing a smaller nav — same shell, same cards, same tables,
 * same colours — and two copies of a design system drift apart within a month.
 *
 * Both pages require this before rendering. Nothing here touches the database or
 * the session: it is markup and CSS only.
 */

function svg(string $name): string {
    $p = [
        'overview'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'leads'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'deals'       => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M3 11v3"/><line x1="7" y1="10" x2="7" y2="15"/>',
        'pipeline'    => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="14" y2="12"/><line x1="4" y1="18" x2="9" y2="18"/>',
        'contacts'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/>',
        'appointments'=> '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'tasks'       => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'agents'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'reminders'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'messages'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'chat'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'campaigns'   => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'mega'        => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'events'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'instructions'=> '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'database'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>',
        'invoices'    => '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6"/><path d="M12 11v7"/><path d="M14 12.5h-3a1.5 1.5 0 0 0 0 3h2a1.5 1.5 0 0 1 0 3H10"/>',
        'payments'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/>',
        'eye'         => '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>',
        'devices'     => '<rect x="4" y="3" width="16" height="12" rx="1"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="15" x2="12" y2="21"/>',
        'partners'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M12 12l2 2 4-4"/>',
        'network_areas' => '<rect x="9" y="2" width="6" height="6" rx="1"/><rect x="3" y="16" width="6" height="6" rx="1"/><rect x="15" y="16" width="6" height="6" rx="1"/><path d="M12 8v4M12 12H6v4M12 12h6v4"/>',
        'link'        => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>',
        'send'        => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'outbound'    => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'templates'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
        'money'       => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'alert'       => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'check'       => '<path d="M20 6 9 17l-5-5"/>',
        'documents'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 16c1.5-2.5 3-2.5 3-1s-1.5 1.5-1 3c2 0 3-1 4-2"/>',
        'sign'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 16c1.5-2.5 3-2.5 3-1s-1.5 1.5-1 3c2 0 3-1 4-2"/>',
        'trophy'      => '<path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0V4z"/><path d="M5 4H3v2a3 3 0 0 0 3 3M19 4h2v2a3 3 0 0 1-3 3"/>',
        'phone'       => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'pen'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    ];
    $body = $p[$name] ?? $p['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

function css(): void { ?>
<style>
:root{
  --bg:#0e131c;--surface:#161c28;--surface2:#1c2533;--line:#28303f;--line2:#39435a;
  --txt:#e7ecf4;--muted:#8b95a7;--accent:#5b6cff;--accent-soft:rgba(91,108,255,.14);
  --green:#3fb868;--green-bg:rgba(63,184,104,.13);--red:#e5616e;--red-bg:rgba(229,97,110,.13);
  --amber:#d9a40a;--amber-bg:rgba(217,164,10,.13);--violet:#7c5cff;--radius:12px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;color:var(--txt);font-size:14px;line-height:1.5;
  background:var(--bg);min-height:100vh;-webkit-font-smoothing:antialiased;}
.center{display:flex;align-items:center;justify-content:center;min-height:100vh;}
.muted{color:var(--muted);} .small{font-size:12px;} .big{font-size:30px;font-weight:700;letter-spacing:-.02em;}
a{color:inherit;text-decoration:none;}
.logo{width:40px;height:40px;border-radius:10px;background:var(--accent);display:flex;align-items:center;
  justify-content:center;font-weight:800;color:#fff;font-size:18px;flex:0 0 auto;}
.shell{display:flex;min-height:100vh;}
.sidebar{width:236px;background:var(--surface);border-right:1px solid var(--line);
  padding:18px 14px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;flex:0 0 auto;}
.brand{display:flex;gap:11px;align-items:center;margin:4px 6px 22px;}
.brand strong{display:block;font-size:15px;} .brand span{display:block;line-height:1.3;margin-top:2px;}
nav{display:flex;flex-direction:column;gap:2px;overflow-y:auto;}
nav a{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:8px;color:var(--muted);
  font-weight:500;transition:background .12s,color .12s;}
nav a svg{width:18px;height:18px;flex:0 0 auto;}
nav a:hover{background:var(--surface2);color:var(--txt);}
nav a.active{background:var(--accent);color:#fff;}
main{flex:1;display:flex;flex-direction:column;min-width:0;}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:13px 28px;
  border-bottom:1px solid var(--line);background:var(--surface);position:sticky;top:0;z-index:5;}
.crumb{font-weight:700;font-size:17px;}
.actions{display:flex;gap:12px;align-items:center;}
.actions .btn.tiny svg{width:14px;height:14px;}
.langsw{display:inline-flex;background:var(--surface2);border:1px solid var(--line);border-radius:8px;padding:2px;}
.langsw a{padding:4px 9px;border-radius:6px;color:var(--muted);font-weight:600;font-size:12px;}
.langsw a.on{background:var(--accent);color:#fff;}
.content{padding:24px 28px;width:100%;}
h2{font-size:21px;margin-bottom:18px;letter-spacing:-.01em;} h3{font-size:15px;margin:16px 0 12px;}
.lead{font-size:15px;color:var(--muted);margin-bottom:20px;line-height:1.65;max-width:820px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:16px;}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px;transition:border-color .12s;}
.tile:hover{border-color:var(--line2);}
.tile-top{display:flex;align-items:center;gap:9px;margin-bottom:10px;color:var(--muted);}
.tile-top svg{width:17px;height:17px;}
.tile .big{display:block;margin-top:6px;} .tile .sub{font-size:12px;color:var(--muted);margin-top:4px;}
.badge{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border-radius:7px;font-size:12.5px;font-weight:600;}
.badge .dot{width:7px;height:7px;border-radius:50%;}
.badge.ok{background:var(--green-bg);color:var(--green);} .badge.ok .dot{background:var(--green);}
.badge.no{background:var(--red-bg);color:var(--red);} .badge.no .dot{background:var(--red);}
.cols{display:grid;gap:16px;margin-bottom:16px;}
.cols.c-2-1{grid-template-columns:2fr 1fr;} .cols.c-1-1{grid-template-columns:1fr 1fr;}
@media(max-width:1100px){.cols.c-2-1,.cols.c-1-1{grid-template-columns:1fr;}}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;}
.panel-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.panel-h h3{margin:0;display:flex;align-items:center;gap:9px;} .panel-h h3 svg{width:17px;height:17px;color:var(--muted);}
.chart-wrap{position:relative;height:240px;} .chart-wrap.sm{height:210px;}
.feed{display:flex;flex-direction:column;gap:2px;}
.feed-row{display:flex;align-items:center;gap:11px;padding:9px 4px;border-bottom:1px solid var(--line);}
.feed-row:last-child{border-bottom:none;}
.feed-ic{width:30px;height:30px;border-radius:8px;background:var(--surface2);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:0 0 auto;}
.feed-ic svg{width:15px;height:15px;}
.feed-main{min-width:0;flex:1;} .feed-main b{font-weight:600;font-size:13.5px;} .feed-main .meta{font-size:12px;color:var(--muted);}
.feed-time{font-size:11.5px;color:var(--muted);white-space:nowrap;}
.empty{color:var(--muted);text-align:center;padding:26px 0;font-size:13px;}
.fld{display:block;margin-bottom:16px;} .fld span{display:block;margin-bottom:7px;color:var(--muted);font-size:13px;font-weight:500;}
input,select,textarea{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--bg);
  color:var(--txt);font-size:14px;outline:none;font-family:inherit;transition:border-color .12s;}
input:focus,select:focus,textarea:focus{border-color:var(--accent);}
input[readonly]{color:var(--muted);cursor:pointer;}
.fld small{display:block;margin-top:6px;font-size:12px;line-height:1.5;}
/* Masked credential field: the eye sits inside the input, not beside it, so the
   field keeps the same width as every other one on the row. */
.secretwrap{position:relative;display:block;}
.secretwrap input{padding-right:40px;}
.peek{position:absolute;top:50%;right:6px;transform:translateY(-50%);background:none;border:0;
  padding:6px;cursor:pointer;color:var(--muted);display:flex;line-height:0;border-radius:6px;}
.peek:hover{color:var(--txt);background:var(--surface2);}
.peek.on{color:var(--accent);}
.peek svg{width:16px;height:16px;}
.row{display:flex;gap:14px;flex-wrap:wrap;} .row .fld{flex:1;min-width:150px;}
.btn{padding:10px 16px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-weight:600;
  cursor:pointer;font-size:14px;transition:filter .12s;display:inline-flex;align-items:center;gap:7px;} .btn:hover{filter:brightness(1.08);}
.btn svg{width:15px;height:15px;}
.btn.ghost{background:var(--surface2);border:1px solid var(--line);color:var(--txt);}
.btn.ghost:hover{border-color:var(--line2);filter:none;background:var(--surface);}
.btn.danger{background:var(--red-bg);border:1px solid var(--red);color:var(--red);}
.btn.danger:hover{background:var(--red);color:#fff;filter:none;}
.btn.tiny{padding:6px 12px;font-size:12.5px;}
.inline{display:inline-flex;gap:8px;align-items:center;margin:0 10px 8px 0;}
.inline input,.inline select{width:auto;}
table{width:100%;border-collapse:separate;border-spacing:0;background:var(--surface);
  border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;}
th,td{text-align:left;padding:11px 14px;border-bottom:1px solid var(--line);vertical-align:middle;}
th{color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;background:var(--surface2);}
tbody tr:hover{background:var(--surface2);} tr:last-child td{border-bottom:none;}
.pill{display:inline-block;padding:4px 10px;border-radius:7px;background:var(--surface2);font-size:12px;font-weight:600;border:1px solid var(--line);text-transform:capitalize;}
.pill-pending,.pill-requested,.pill-open{color:var(--amber);background:var(--amber-bg);border-color:transparent;}
.pill-sent,.pill-confirmed,.pill-done,.pill-won,.pill-converted{color:var(--green);background:var(--green-bg);border-color:transparent;}
.pill-failed,.pill-cancelled,.pill-lost,.pill-junk,.pill-no_show{color:var(--red);background:var(--red-bg);border-color:transparent;}
.reason-err{color:var(--red);word-break:break-word;max-width:340px;}
.flash{background:var(--green-bg);border:1px solid var(--green);color:var(--green);padding:12px 16px;
  border-radius:8px;margin-bottom:18px;word-break:break-word;font-weight:500;}
.flash-err{background:var(--red-bg);border-color:var(--red);color:var(--red);}
.warn{background:var(--amber-bg);border:1px solid var(--amber);color:var(--amber);padding:12px 16px;
  border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.55;}
.step{background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--accent);
  border-radius:10px;padding:17px 21px;margin-bottom:14px;}
.step.accent{border-left-color:var(--amber);} .step p{line-height:1.65;color:var(--muted);} .step b{color:var(--txt);font-weight:600;}
.tabs{display:inline-flex;gap:4px;margin-bottom:16px;background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:3px;}
.tabs a{padding:7px 14px;border-radius:7px;color:var(--muted);font-size:13px;font-weight:500;}
.tabs a.on{background:var(--accent);color:#fff;}
.login{background:var(--surface);padding:38px 36px;border-radius:14px;width:360px;text-align:center;border:1px solid var(--line);}
.login .logo{margin:0 auto 18px;width:50px;height:50px;font-size:22px;} .login h1{font-size:21px;margin-bottom:5px;}
.login input{margin:9px 0;} .login button{width:100%;margin-top:10px;}
.err{color:var(--red);font-size:13px;margin-bottom:8px;}
/* kanban */
.kanban{display:flex;gap:14px;overflow-x:auto;padding-bottom:8px;}
.kcol{flex:0 0 270px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);display:flex;flex-direction:column;max-height:72vh;}
.kcol-h{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;}
.kcol-h .dotc{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:8px;}
.kcol-h .cnt{font-size:11px;color:var(--muted);background:var(--surface2);border-radius:20px;padding:2px 8px;}
.kbody{padding:10px;display:flex;flex-direction:column;gap:9px;overflow-y:auto;min-height:60px;}
.kbody.drag{outline:2px dashed var(--line2);outline-offset:-6px;border-radius:8px;}
.kcard{background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:11px 12px;cursor:grab;}
.kcard:hover{border-color:var(--line2);} .kcard b{font-size:13.5px;font-weight:600;}
.kcard .meta{font-size:11.5px;color:var(--muted);margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.kcard .amt{color:var(--green);font-weight:600;}
/* Note preview under a lead (kanban card + list row). It used to be a single nowrap
   line cut with an ellipsis: on a long note that showed the first few words and
   nothing else. Wrap over several lines and clamp instead, so the note is readable
   while the card keeps a predictable height. pre-line keeps the author's newlines. */
.note-clip{display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden;
  white-space:pre-line;overflow-wrap:anywhere;font-style:italic;line-height:1.45;}
.note-clip.l2{-webkit-line-clamp:2;line-clamp:2;}
.note-clip.l4{-webkit-line-clamp:4;line-clamp:4;}
/* "typed in by hand" marker — tells a lead someone keyed in from a lead that
   arrived on its own (public form / fair form / partner API). */
.byhand{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:6px;
  background:var(--amber-bg);color:var(--amber);font-size:11px;font-weight:600;white-space:nowrap;}
.byhand svg{width:11px;height:11px;flex:0 0 auto;}
/* "brought in by a partner" marker — which referrer this lead belongs to. Its own
   colour, not .byhand's: a partner lead and a hand-keyed lead are different facts
   and both can be true at once. */
.bypartner{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:6px;
  background:var(--accent-soft);color:var(--accent);font-size:11px;font-weight:600;white-space:nowrap;}
.bypartner svg{width:11px;height:11px;flex:0 0 auto;}
.avatar{display:inline-flex;width:22px;height:22px;border-radius:50%;background:var(--accent-soft);color:var(--accent);
  align-items:center;justify-content:center;font-size:11px;font-weight:700;}
.tl{display:flex;flex-direction:column;gap:0;}
.tl-row{display:flex;gap:11px;padding:9px 0;border-bottom:1px solid var(--line);}
.tl-row:last-child{border-bottom:none;}
.tl-ic{width:26px;height:26px;border-radius:7px;background:var(--surface2);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:0 0 auto;}
.tl-ic svg{width:13px;height:13px;} .tl-main{flex:1;min-width:0;} .tl-main .meta{font-size:11.5px;color:var(--muted);}
.lb{display:flex;align-items:center;gap:10px;padding:10px 4px;border-bottom:1px solid var(--line);}
.lb:last-child{border-bottom:none;} .lb .nm{flex:1;font-weight:600;} .lb .sc{font-weight:700;color:var(--accent);}
.lb .mini{font-size:11.5px;color:var(--muted);}
details.drawer{margin-bottom:8px;} details.drawer>summary{cursor:pointer;list-style:none;}
details.drawer>summary::-webkit-details-marker{display:none;}
/* Drawer header row (leads/deals/partners/agents): avatar · .dw-info · pills · agent.
   .dw-info carries the name + phone + email and absorbs the slack. */
summary.dw-sum{display:flex;align-items:center;gap:12px;padding:13px 18px;}
.dw-info{flex:1;min-width:0;}
/* Click-to-call number: accent-coloured link + phone glyph, sits inline in muted text. */
a.tel{display:inline-flex;align-items:center;gap:4px;color:var(--accent);white-space:nowrap;vertical-align:baseline;}
a.tel svg{width:13px;height:13px;flex:0 0 auto;}
a.tel:hover{text-decoration:underline;}
@media(max-width:560px){
  /* A phone number is one unbreakable token. Squeezed into what the pills leave over on
     a narrow screen it overflowed the card and agents could not read it, so let the row
     wrap and give .dw-info the full width. */
  summary.dw-sum{flex-wrap:wrap;row-gap:6px;}
  .dw-info{flex-basis:100%;}
}
/* hamburger (mobile only) + off-canvas backdrop */
.navtoggle{display:none;background:var(--surface2);border:1px solid var(--line);color:var(--txt);
  border-radius:8px;padding:7px;cursor:pointer;align-items:center;justify-content:center;margin-right:4px;}
.navtoggle svg{width:20px;height:20px;display:block;}
.nav-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40;}
@media(max-width:900px){
  /* Sidebar becomes a slide-in drawer with full labels — no more icon-only rail. */
  .sidebar{position:fixed;top:0;left:0;height:100dvh;width:248px;z-index:50;
    transform:translateX(-100%);transition:transform .22s ease;box-shadow:0 0 40px rgba(0,0,0,.4);}
  .sidebar.open{transform:translateX(0);}
  .nav-backdrop.show{display:block;}
  .navtoggle{display:inline-flex;}
  .topbar{padding:11px 16px;gap:10px;}
  .crumb{font-size:16px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .content{padding:16px;}
  /* Trim the topbar so it fits a phone: drop the public-form button + username. */
  .actions{gap:8px;} .actions .btn.tiny span{display:none;}
  .topbar .pubform,.topbar .who{display:none;}
}
@media(max-width:560px){
  .row{flex-direction:column;gap:0;} .row .fld{min-width:0;}
  .grid{grid-template-columns:1fr;}
  .cols.c-2-1,.cols.c-1-1{grid-template-columns:1fr;}
  .langsw a{padding:4px 8px;}
  .login{width:100%;max-width:360px;padding:30px 22px;}
  .tabs{display:flex;flex-wrap:wrap;}
  th,td{padding:9px 11px;}
}
/* Wide tables scroll sideways inside their own box instead of overflowing the
   page. .table-wrap is added around every table by JS (see render_foot). */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:16px;}
.table-wrap table{margin-bottom:0;}
@media(max-width:560px){.table-wrap table{min-width:520px;}}
</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php }
