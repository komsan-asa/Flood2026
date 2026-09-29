<?php
/**
 * แนะนำระบบ (สาธารณะ) — index/about · อินโฟกราฟิกแนวตั้งแบบเดียวกับหน้า coc/index/about
 * เรนเดอร์แบบ standalone (View::rander(..., true)) ไม่ผ่าน header/menutop/footer — CSS อยู่ในหน้านี้ ไม่ชนกับธีมของแอป
 * ตัวเลขสดมาจาก Index::about() · ไม่มีข้อมูลส่วนบุคคล
 */
$st = is_array($this->aboutStats) ? $this->aboutStats : array();
$n = function ($k) use ($st) { return number_format((int) (isset($st[$k]) ? $st[$k] : 0)); };
$levels = function_exists('flood_zone_levels') ? flood_zone_levels() : array();
$roles = function_exists('flood_role_labels') ? flood_role_labels() : array();
$satItems = (int) (isset($st['sat_items']) ? $st['sat_items'] : 14);
$ddpm = function_exists('flood_ddpm_phone') ? flood_ddpm_phone() : '1784';
$asOf = flood_thai_date(time());
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>แนะนำระบบ — Sakaeo Flood</title>
<meta name="description" content="แนะนำระบบ Sakaeo Flood — ระบบแจ้งจุดน้ำท่วม ประสานการช่วยเหลือ และห้องสถานการณ์ (SAT) ของโรงพยาบาล" />
<link rel="icon" type="image/svg+xml" href="<?= URL ?>public/img/favicon.svg" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap">
<style>
/* Sakaeo Flood — อินโฟกราฟิกแนวตั้ง (โครงเดียวกับหน้าแนะนำระบบ Sakaeo COC)
   พาเลตต์: ฟ้าน้ำ/น้ำเงินของระบบ + 4 สีสถานะโรงพยาบาลของ SAT (เขียว เหลือง ส้ม แดง) */
:root {
  --blue: #1E8FD6;
  --blue-deep: #0E6BB0;
  --blue-ink: #0B3A66;
  --teal: #13A8B8;
  --green: #2EA36B;
  --yellow: #E3A51A;
  --orange: #EE7A2B;
  --red: #E0453A;

  --ink: #14283D;
  --muted: #5A6F85;
  --hair: #D3E3F2;
  --panel: #FFFFFF;
  --tint: #F4F9FE;

  --w: 1080px;
  --pad: 26px;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  background: #DCEAF6;
  color: var(--ink);
  font-family: "Sarabun", "Leelawadee UI", system-ui, sans-serif;
  font-size: 16.5px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
h1, h2, h3, .num, .tier-code { font-family: "IBM Plex Sans Thai", "Sarabun", sans-serif; }
.num { font-variant-numeric: tabular-nums; }
a { color: inherit; }

/* ---------- แถบนำทางเหนือโปสเตอร์ ---------- */
.topbar {
  max-width: var(--w); margin: 0 auto;
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  padding: 16px var(--pad) 10px; font-size: 14.5px;
}
.topbar a { color: var(--blue-deep); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
.topbar a:hover, .topbar a:focus-visible { text-decoration: underline; }
.topbar a:focus-visible { outline: 2px solid var(--blue); outline-offset: 3px; border-radius: 4px; }
.topbar-r { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
.topbar-brand { color: var(--muted); font-weight: 600; }
.topbar-brand .by-top { margin-left: 6px; padding: 1px 8px; border-radius: 6px; background: var(--blue-ink); color: #fff; font-weight: 700; letter-spacing: .5px; }

.sheet {
  width: var(--w); margin: 0 auto 32px;
  background: linear-gradient(180deg, #F7FBFF 0%, #EEF5FD 45%, #EAF6F4 100%);
  border-radius: 16px; overflow: hidden;
  box-shadow: 0 18px 40px rgba(11,58,102,.13);
}

/* ---------- หัวเรื่อง ---------- */
.head {
  position: relative; padding: 30px var(--pad) 28px; color: #fff;
  background:
    radial-gradient(120% 130% at 82% 8%, rgba(255,255,255,.28) 0%, rgba(255,255,255,0) 55%),
    linear-gradient(135deg, #0B5FA5 0%, #1E8FD6 52%, #3FC0D8 100%);
}
.head h1 { margin: 0; font-size: 58px; font-weight: 700; letter-spacing: -.5px; line-height: 1.05; text-wrap: balance; }
.head .tag {
  display: inline-block; margin: 12px 0 0; padding: 7px 18px; border-radius: 999px;
  background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.34);
  font-size: 23px; font-weight: 600;
}
.head p { margin: 12px 0 0; font-size: 16.5px; color: #E2F2FF; max-width: 62ch; }
.head p.org { margin-top: 5px; color: #CDE8FF; }
.nowrap { white-space: nowrap; }
.head-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 22px; }
.head-art { flex: 0 0 300px; display: flex; flex-direction: column; align-items: flex-end; gap: 14px; }
.head-art svg { max-width: 100%; height: auto; display: block; }
.pulse-note { position: absolute; right: 26px; bottom: 16px; font-size: 14.5px; color: #DDF0FF; font-style: italic; }
.head-cta {
  display: inline-flex; align-items: center; gap: 8px;
  background: #fff; color: var(--blue-deep); border: 0; cursor: pointer; font: inherit;
  padding: 9px 16px; border-radius: 999px; font-weight: 700; font-size: 14px; white-space: nowrap;
  box-shadow: 0 4px 14px rgba(11,58,102,.22); transition: transform .15s ease, box-shadow .15s ease;
}
.head-cta:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(11,58,102,.3); }
.head-cta:focus-visible { outline: 2px solid #fff; outline-offset: 3px; }
.head-cta[disabled] { opacity: .7; cursor: progress; }

.brand { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 16px; }
.by {
  display: inline-flex; align-items: baseline; gap: 8px; padding: 6px 18px 8px; border-radius: 14px;
  background: #fff; color: var(--blue-ink); border-left: 6px solid var(--teal);
  font-family: "IBM Plex Sans Thai", sans-serif; font-weight: 700; font-size: 42px; line-height: 1; letter-spacing: 1px;
  box-shadow: 0 6px 18px rgba(11,58,102,.28);
}
.by .by-w { font-size: 21px; font-weight: 600; color: var(--blue); letter-spacing: 0; }

/* ---------- แถบหัวข้อ ---------- */
.band { padding: 22px var(--pad); }
.band + .band { padding-top: 4px; }
.band-head { display: flex; align-items: baseline; gap: 10px; margin: 0 0 14px; }
.band-head h2 { margin: 0; font-size: 26px; font-weight: 700; color: var(--blue-ink); display: flex; align-items: center; gap: 9px; }
.band-head .sub { font-size: 15.5px; color: var(--muted); margin-left: auto; text-align: right; }

/* ---------- 5 ขั้นตอน ---------- */
.flow { display: grid; grid-template-columns: repeat(5, 1fr); gap: 9px; }
.step {
  background: var(--panel); border: 1px solid var(--hair); border-radius: 12px;
  padding: 14px 12px 13px; display: flex; flex-direction: column; gap: 7px; position: relative;
}
.step::after {
  content: ""; position: absolute; right: -7px; top: 46px; width: 0; height: 0;
  border-left: 7px solid var(--blue); border-top: 6px solid transparent; border-bottom: 6px solid transparent;
}
.step:last-child::after { display: none; }
.step .n { width: 33px; height: 33px; border-radius: 10px; display: grid; place-items: center;
  font-family: "IBM Plex Sans Thai", sans-serif; font-weight: 700; font-size: 18.5px; color: #fff; }
.step h3 { margin: 0; font-size: 17px; font-weight: 700; line-height: 1.25; }
.step p { margin: 0; font-size: 14px; color: var(--muted); line-height: 1.42; }

/* ---------- ตัวเลขเด่น ---------- */
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 11px; }
.stat { border-radius: 12px; padding: 15px 16px; display: flex; align-items: center; gap: 14px; border: 1px solid var(--hair); background: var(--panel); }
.stat .fig { font-size: 44px; font-weight: 700; line-height: 1; }
.stat .lb { font-size: 15px; color: var(--muted); line-height: 1.35; }
.stat .lb b { display: block; color: var(--ink); font-size: 16px; font-weight: 600; }
.live {
  margin-top: 10px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 18px;
  padding: 9px 14px; border-radius: 10px; background: #fff; border: 1px dashed var(--hair); font-size: 14.5px; color: var(--muted);
}
.live b { color: var(--ink); }
.live-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--red); box-shadow: 0 0 0 4px rgba(224,69,58,.18); }

/* ---------- 3 เสาหลัก ---------- */
.pillars { display: grid; grid-template-columns: repeat(3, 1fr); gap: 13px; }
.pillar { border-radius: 14px; overflow: hidden; border: 1px solid var(--hair); background: var(--panel); }
.pillar-top { padding: 14px 16px 12px; display: flex; align-items: center; gap: 11px; color: #fff; }
.pillar-top h3 { margin: 0; font-size: 22px; font-weight: 700; line-height: 1.15; }
.pillar-top .cap { font-size: 14px; opacity: .93; display: block; font-weight: 400; }
.pillar ul { list-style: none; margin: 0; padding: 12px 14px 14px; display: grid; gap: 10px; }
.pillar li { display: flex; gap: 10px; align-items: flex-start; }
.pillar li svg { flex: 0 0 auto; margin-top: 3px; }
.pillar li b { display: block; font-size: 16px; font-weight: 600; }
.pillar li span { font-size: 14px; color: var(--muted); line-height: 1.42; }

/* ---------- เชื่อมข้อมูล + สถานะ 4 สี ---------- */
.duo { display: grid; grid-template-columns: 1.06fr 1fr; gap: 13px; }
.panel { background: var(--panel); border: 1px solid var(--hair); border-radius: 14px; padding: 15px 16px; }
.panel > h3 { margin: 0 0 12px; font-size: 18.5px; font-weight: 700; color: var(--blue-ink); display: flex; align-items: center; gap: 8px; }
.his { display: grid; grid-template-columns: repeat(4, 1fr); gap: 9px; }
.his div { text-align: center; }
.his .ico { width: 42px; height: 42px; margin: 0 auto 7px; border-radius: 11px; display: grid; place-items: center; background: var(--tint); border: 1px solid var(--hair); }
.his p { margin: 0; font-size: 13.5px; color: var(--muted); line-height: 1.38; }
.his p b { display: block; color: var(--ink); font-size: 14.5px; font-weight: 600; margin-bottom: 1px; }
.note { margin: 12px 0 0; font-size: 14px; color: var(--muted); }

.tiers { display: grid; gap: 7px; }
.tier { display: grid; grid-template-columns: 74px 1fr; align-items: center; gap: 11px;
  padding: 8px 11px; border-radius: 10px; background: var(--tint); border: 1px solid var(--hair); }
.tier-code { font-weight: 700; font-size: 14px; color: #fff; text-align: center; padding: 4px 0; border-radius: 7px; letter-spacing: .3px; }
.tier b { font-size: 15px; font-weight: 600; display: block; }
.tier span { font-size: 13.5px; color: var(--muted); }

/* ---------- รอบอัปเดต + ประโยชน์ ---------- */
.goals { display: grid; grid-template-columns: repeat(3, 1fr); gap: 11px; }
.goal { background: var(--panel); border: 1px solid var(--hair); border-radius: 12px; padding: 13px 14px; display: flex; align-items: center; gap: 13px; }
.goal .fig { font-size: 30px; font-weight: 700; line-height: 1; font-family: "IBM Plex Sans Thai", sans-serif; white-space: nowrap; }
.goal p { margin: 0; font-size: 14px; color: var(--muted); line-height: 1.35; }
.goal p b { display: block; color: var(--ink); font-size: 15.5px; font-weight: 600; }

.perks { display: grid; grid-template-columns: 1fr 1fr; gap: 9px 18px; }
.perk { display: flex; gap: 9px; align-items: flex-start; font-size: 15px; }
.perk svg { flex: 0 0 auto; margin-top: 2px; }

.levels { display: flex; flex-wrap: wrap; gap: 7px; }
.lv { display: inline-flex; align-items: center; gap: 7px; padding: 5px 11px 5px 8px; border-radius: 999px; background: #fff; border: 1px solid var(--hair); font-size: 14px; font-weight: 600; }
.lv i { width: 12px; height: 12px; border-radius: 50%; background: var(--lv); flex: 0 0 auto; }

/* ---------- ท้ายภาพ ---------- */
.foot {
  margin-top: 6px; padding: 20px var(--pad) 22px; color: #fff;
  background: linear-gradient(135deg, #0A4A82 0%, #0E6BB0 55%, #1FA3C8 100%);
  display: flex; align-items: center; justify-content: space-between; gap: 22px; flex-wrap: wrap;
}
.foot h3 { margin: 0 0 4px; font-size: 24px; font-weight: 700; }
.foot p { margin: 0; font-size: 15px; color: #D6EDFF; }
.foot .pillbar { display: flex; gap: 8px; margin-top: 11px; flex-wrap: wrap; }
.foot .pill { font-size: 14px; padding: 5px 12px; border-radius: 999px; background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3); }
.stamp { text-align: right; flex: 0 0 auto; }
.stamp .big { font-size: 16.5px; font-weight: 600; }
.stamp .sm { font-size: 13.5px; color: #CBE6FF; margin-top: 3px; }
.stamp .by-sm { display: inline-block; margin-top: 5px; padding: 2px 10px; border-radius: 7px; background: #fff; color: var(--blue-ink); font-weight: 700; font-size: 16px; letter-spacing: .5px; }

.dev-credit {
  display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap;
  padding: 13px var(--pad) 14px; background: var(--blue-ink); color: #fff; font-size: 16.5px; line-height: 1.4; text-align: center;
}
.dev-credit .lbl { color: #9FD3FF; font-weight: 500; }
.dev-credit b { font-weight: 700; }
.dev-credit .sep { color: #5C88B6; }

@media (max-width: 1120px) {
  .sheet, .topbar { width: 96vw; max-width: var(--w); }
}
@media (max-width: 900px) {
  :root { --pad: 18px; }
  .head h1 { font-size: 42px; }
  .by { font-size: 32px; }
  .by .by-w { font-size: 17px; }
  .head .tag { font-size: 18px; padding: 6px 14px; }
  .head p { font-size: 15px; }
  .band-head h2 { font-size: 21px; }
  .band-head .sub { font-size: 13.5px; }
  .flow { grid-template-columns: repeat(2, 1fr); }
  .step::after { display: none; }
  .stats { grid-template-columns: repeat(2, 1fr); }
  .stat .fig { font-size: 36px; }
  .pillars { grid-template-columns: 1fr; }
  .duo { grid-template-columns: 1fr; }
  .goals { grid-template-columns: 1fr; }
  .foot { flex-direction: column; align-items: flex-start; }
  .stamp { text-align: left; }
}
@media (max-width: 600px) {
  :root { --pad: 14px; }
  .topbar { padding: 12px var(--pad) 6px; font-size: 13px; }
  .head { padding: 22px var(--pad) 20px; }
  .head h1 { font-size: 30px; letter-spacing: -.3px; }
  .by { font-size: 24px; padding: 5px 12px 6px; }
  .by .by-w { font-size: 14px; }
  .head .tag { font-size: 14px; padding: 5px 12px; margin-top: 10px; }
  .head p { font-size: 13.5px; max-width: none; }
  .head p.org { font-size: 12.5px; }
  .pulse-note { position: static; display: block; text-align: center; margin-top: 16px; }
  .head-row { flex-direction: column; gap: 16px; }
  .head-art { flex: 0 0 auto; width: 100%; align-items: center; }
  .head-art svg { max-width: 220px; }
  .head-cta { width: 100%; justify-content: center; }
  .band { padding: 16px var(--pad); }
  .band-head { flex-wrap: wrap; row-gap: 4px; }
  .band-head .sub { margin-left: 0; text-align: left; flex-basis: 100%; }
  .flow { grid-template-columns: 1fr; }
  .stats { gap: 8px; }
  .stat { padding: 12px; gap: 10px; }
  .stat .fig { font-size: 28px; }
  .stat .lb { font-size: 12.5px; }
  .stat .lb b { font-size: 13.5px; }
  .pillar-top h3 { font-size: 19px; }
  .pillar li b { font-size: 14.5px; }
  .pillar li span { font-size: 13px; }
  .his { grid-template-columns: repeat(2, 1fr); row-gap: 14px; }
  .tier { grid-template-columns: 62px 1fr; padding: 8px; }
  .perks { grid-template-columns: 1fr; }
  .foot h3 { font-size: 19px; }
  .foot p { font-size: 13.5px; }
  .foot .pill { font-size: 12px; padding: 4px 10px; }
}
@media print {
  body { background: #fff; }
  .topbar, .head-cta { display: none; }
  .sheet { width: 100%; box-shadow: none; border-radius: 0; }
}
</style>
</head>
<body>

<div class="topbar">
  <a href="<?= URL ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7" stroke="#0E6BB0" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
    กลับหน้าแผนที่
  </a>
  <span class="topbar-r">
    <a href="<?= URL ?>index/situation">สถานการณ์ทั่วประเทศ</a>
    <a href="<?= URL ?>login">ภาพรวม / เข้าสู่ระบบเจ้าหน้าที่</a>
    <span class="topbar-brand">ระบบ Sakaeo Flood<span class="by-top">by SCPH</span></span>
  </span>
</div>

<div class="sheet" id="abSheet">

  <!-- ================= หัวเรื่อง ================= -->
  <header class="head">
    <div class="head-row">
      <div>
        <div class="brand">
          <h1>ระบบ Sakaeo Flood</h1>
          <span class="by"><span class="by-w">by</span>SCPH</span>
        </div>
        <span class="tag">รู้สถานการณ์น้ำ รู้ความพร้อมโรงพยาบาล ในระบบเดียว</span>
        <p>Web Application แจ้งจุดน้ำท่วม ประกาศพื้นที่ และประสานการช่วยเหลือผู้ประสบภัย
           เชื่อมกับ<span class="nowrap">ห้องสถานการณ์ (SAT)</span> ของโรงพยาบาล
           ให้ประชาชน ศูนย์ประสาน และผู้บริหารเห็นภาพเดียวกัน</p>
        <p class="org">โรงพยาบาลสมเด็จพระยุพราชสระแก้ว · ครอบคลุม <?= $n('provinces') ?> จังหวัด <?= $n('amphoes') ?> อำเภอ</p>
      </div>
      <div class="head-art">
        <button type="button" class="head-cta" id="abSaveImg" data-html2canvas-ignore title="ดาวน์โหลดอินโฟกราฟิกนี้เป็นไฟล์ภาพ PNG">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14" stroke="#0B3A66" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>ดาวน์โหลดเป็นรูปภาพ</span>
        </button>
        <svg viewBox="0 0 300 150" width="300" height="150" role="img" aria-label="จุดน้ำท่วม ศูนย์ประสาน และโรงพยาบาล เชื่อมถึงกัน">
          <!-- เส้นเชื่อม -->
          <path d="M60 78 C 110 40, 170 40, 238 70" fill="none" stroke="rgba(255,255,255,.6)" stroke-width="2.5" stroke-dasharray="7 6"/>
          <!-- หมุดจุดน้ำท่วม + บ้าน -->
          <rect x="22" y="84" width="54" height="34" rx="5" fill="#fff"/>
          <path d="M18 86 L49 62 L80 86 Z" fill="#BEE3FA"/>
          <rect x="42" y="98" width="12" height="20" rx="2" fill="#DCEFFB"/>
          <path d="M49 28 c-8 0 -13 6 -13 13 c0 9 13 21 13 21 s13-12 13-21 c0-7 -5-13 -13-13z" fill="#E0453A"/>
          <circle cx="49" cy="41" r="4.5" fill="#fff"/>
          <!-- ศูนย์ประสาน (แผนที่) -->
          <rect x="120" y="70" width="60" height="48" rx="6" fill="#fff"/>
          <path d="M128 80 l12 -4 l14 5 l12 -4 v26 l-12 4 l-14 -5 l-12 4z" fill="#D6EEFB" stroke="#1E8FD6" stroke-width="1.5" stroke-linejoin="round"/>
          <circle cx="147" cy="92" r="5" fill="#EE7A2B"/>
          <!-- โรงพยาบาล -->
          <rect x="214" y="72" width="70" height="46" rx="5" fill="#fff"/>
          <rect x="214" y="72" width="70" height="12" rx="5" fill="#BEE3FA"/>
          <rect x="242" y="89" width="14" height="4" rx="2" fill="#E0453A"/>
          <rect x="247" y="84" width="4" height="14" rx="2" fill="#E0453A"/>
          <rect x="222" y="102" width="12" height="16" rx="2" fill="#DCEFFB"/>
          <rect x="264" y="102" width="12" height="16" rx="2" fill="#DCEFFB"/>
          <!-- สถานะ 4 สี -->
          <circle cx="226" cy="60" r="5" fill="#2EA36B"/><circle cx="241" cy="60" r="5" fill="#E3A51A"/>
          <circle cx="256" cy="60" r="5" fill="#EE7A2B"/><circle cx="271" cy="60" r="5" fill="#E0453A"/>
          <!-- น้ำ -->
          <path d="M6 126 c12 0 12-6 24-6 s12 6 24 6 12-6 24-6 12 6 24 6 12-6 24-6 12 6 24 6 12-6 24-6 12 6 24 6 12-6 24-6 12 6 24 6 12-6 24-6 12 6 24 6" fill="none" stroke="rgba(255,255,255,.75)" stroke-width="3" stroke-linecap="round"/>
          <path d="M6 138 c12 0 12-6 24-6 s12 6 24 6 12-6 24-6 12 6 24 6 12-6 24-6 12 6 24 6 12-6 24-6 12 6 24 6 12-6 24-6 12 6 24 6 12-6 24-6 12 6 24 6" fill="none" stroke="rgba(255,255,255,.45)" stroke-width="3" stroke-linecap="round"/>
        </svg>
      </div>
    </div>
    <div class="pulse-note">“ข้อมูลที่ถูกต้อง ไปถึงคนที่ต้องใช้ ทันเวลา”</div>
  </header>

  <!-- ================= 5 ขั้นตอน ================= -->
  <section class="band">
    <div class="band-head">
      <h2>
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12h13M13 7l5 5-5 5" stroke="#1E8FD6" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        ขั้นตอนการทำงานของระบบ
      </h2>
      <span class="sub">จากจุดน้ำท่วม สู่ศูนย์ประสาน สู่ห้องสถานการณ์โรงพยาบาล</span>
    </div>
    <div class="flow">
      <div class="step">
        <div class="n" style="background:#E0453A">1</div>
        <h3>ประชาชนแจ้ง</h3>
        <p>แจ้งจุดน้ำท่วมด้วย GPS / ปักหมุด / แนบรูป หรือกด SOS ขอความช่วยเหลือ ไม่ต้องสมัครสมาชิก</p>
      </div>
      <div class="step">
        <div class="n" style="background:#EE7A2B">2</div>
        <h3>ศูนย์ตรวจสอบ</h3>
        <p>เจ้าหน้าที่ตรวจรายงาน โทรยืนยัน รวมรายงานซ้ำ คัดข่าวและข้อมูลจากหน่วยงาน</p>
      </div>
      <div class="step">
        <div class="n" style="background:#1E8FD6">3</div>
        <h3>ประกาศพื้นที่</h3>
        <p>ประกาศขอบเขตบนแผนที่ แยกสีตามระดับความรุนแรง ประชาชนเห็นทันที</p>
      </div>
      <div class="step">
        <div class="n" style="background:#13A8B8">4</div>
        <h3>ช่วยเหลือและติดตาม</h3>
        <p>ออกใบงานให้ทีมในพื้นที่ ดูแลกลุ่มเปราะบาง ติดตามจนปิดงาน</p>
      </div>
      <div class="step">
        <div class="n" style="background:#2EA36B">5</div>
        <h3>SAT โรงพยาบาล</h3>
        <p>ประเมินสถานะ 4 สี ออก SitRep ถึง EOC เพื่อตัดสินใจได้ทันเวลา</p>
      </div>
    </div>
  </section>

  <!-- ================= ตัวเลขเด่น ================= -->
  <section class="band">
    <div class="stats">
      <div class="stat" style="background:#EEF6FE">
        <div class="fig num" style="color:#1E8FD6"><?= $n('provinces') ?></div>
        <div class="lb"><b>จังหวัด</b><?= $n('amphoes') ?> อำเภอ</div>
      </div>
      <div class="stat" style="background:#ECF8F9">
        <div class="fig num" style="color:#0E8C9A"><?= $n('tambons') ?></div>
        <div class="lb"><b>ตำบลในระบบ</b>กรองและประกาศได้ถึงระดับตำบล</div>
      </div>
      <div class="stat" style="background:#FEF3EA">
        <div class="fig num" style="color:#D0661E"><?= count($levels) ?></div>
        <div class="lb"><b>ระดับพื้นที่ประกาศ</b>แยกสีบนแผนที่</div>
      </div>
      <div class="stat" style="background:#EDF8F2">
        <div class="fig num" style="color:#23905C"><?= $satItems ?></div>
        <div class="lb"><b>ตัวชี้วัด SAT</b>ของโรงพยาบาล</div>
      </div>
    </div>
    <div class="live">
      <span class="live-dot" aria-hidden="true"></span>
      <span>ขณะนี้ ประกาศอยู่ <b class="num"><?= $n('zones') ?></b> พื้นที่</span>
      <span>จุดสังเกตยืนยันแล้ว <b class="num"><?= $n('points') ?></b></span>
      <span>รอตรวจสอบ <b class="num"><?= $n('pending') ?></b></span>
      <?php if ((int) (isset($st['visitors']) ? $st['visitors'] : 0) >= 100) { ?><span>ผู้เข้าชมสะสม <b class="num"><?= $n('visitors') ?></b></span><?php } ?>
      <span style="margin-left:auto">ข้อมูล ณ <?= h($asOf) ?> น.</span>
    </div>
  </section>

  <!-- ================= 3 เสาหลัก ================= -->
  <section class="band">
    <div class="band-head">
      <h2>
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3l2.6 5.6 6.1.8-4.5 4.2 1.2 6-5.4-3-5.4 3 1.2-6L3.3 9.4l6.1-.8z" fill="#1E8FD6"/></svg>
        จุดเด่นของระบบ
      </h2>
      <span class="sub">สามงานหลักที่ทำได้ครบในระบบเดียว</span>
    </div>
    <div class="pillars">

      <div class="pillar">
        <div class="pillar-top" style="background:linear-gradient(120deg,#0E6BB0,#1E8FD6)">
          <svg width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 6.5l6-2.5 6 2.5 6-2.5v13.5l-6 2.5-6-2.5-6 2.5z" stroke="#fff" stroke-width="1.9" stroke-linejoin="round"/><path d="M9 4v13.5M15 6.5V20" stroke="#fff" stroke-width="1.9"/></svg>
          <h3>สถานการณ์น้ำ<span class="cap">แจ้ง · ตรวจสอบ · ประกาศ</span></h3>
        </div>
        <ul>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s-6.5-6.2-6.5-11A6.5 6.5 0 0112 3.5 6.5 6.5 0 0118.5 10c0 4.8-6.5 11-6.5 11z" stroke="#1E8FD6" stroke-width="1.9"/><circle cx="12" cy="10" r="2.3" fill="#1E8FD6"/></svg>
            <div><b>แผนที่สถานการณ์ + แจ้งจุดน้ำ</b><span>ประชาชนแจ้งจากมือถือ ขึ้นแผนที่ทันทีพร้อมป้าย “รอตรวจสอบ”</span></div></li>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 18c2 0 2-1.5 4-1.5s2 1.5 4 1.5 2-1.5 4-1.5 2 1.5 4 1.5M12 3.5s-4.5 5-4.5 8a4.5 4.5 0 009 0c0-3-4.5-8-4.5-8z" stroke="#1E8FD6" stroke-width="1.9" stroke-linecap="round"/></svg>
            <div><b>ประกาศพื้นที่ <?= count($levels) ?> ระดับ</b><span>รวมจุดน้ำท่วมทางหลวงจากกรมทางหลวง ระดับน้ำและฝนจาก ThaiWater</span></div></li>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20s-7-4.5-7-9.2A4.1 4.1 0 0112 8.4a4.1 4.1 0 017 2.4C19 15.5 12 20 12 20z" stroke="#1E8FD6" stroke-width="1.9"/></svg>
            <div><b>SOS · ใบงาน · ทีมช่วยเหลือ</b><span>ทุกคำขอมีเลข มีผู้รับผิดชอบ ผู้แจ้งติดตามสถานะได้เอง</span></div></li>
        </ul>
      </div>

      <div class="pillar">
        <div class="pillar-top" style="background:linear-gradient(120deg,#D0661E,#EE7A2B)">
          <svg width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2" stroke="#fff" stroke-width="1.9"/><path d="M12 8.5v7M8.5 12h7" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
          <h3>ห้องสถานการณ์ SAT<span class="cap">ความพร้อมของโรงพยาบาล</span></h3>
        </div>
        <ul>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1.6" fill="#2EA36B"/><rect x="13" y="4" width="7" height="7" rx="1.6" fill="#E3A51A"/><rect x="4" y="13" width="7" height="7" rx="1.6" fill="#EE7A2B"/><rect x="13" y="13" width="7" height="7" rx="1.6" fill="#E0453A"/></svg>
            <div><b><?= $satItems ?> ตัวชี้วัด สถานะ 4 สี</b><span>ฝน ระดับน้ำ ถนน หน่วยบริการ EMS/Refer บุคลากร ผู้ป่วย ไฟ น้ำ O2 เชื้อเพลิง IT</span></div></li>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 3.5h8l4.5 4.5V20.5H6z" stroke="#D0661E" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3.5V8h4.5M9 13h6M9 16.5h4" stroke="#D0661E" stroke-width="1.8" stroke-linecap="round"/></svg>
            <div><b>SitRep ถึง EOC</b><span>ร่างจากข้อมูลทั้งหน้า แก้ไขได้ คัดลอกส่ง LINE หรือบันทึกเป็นรูป</span></div></li>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 18L9 12l4 3 7-9" stroke="#D0661E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div><b>3 เส้นทาง + หน่วยบริการ</b><span>เข้า รพ. / ออกจาก รพ. / Refer · โทรติดตามหน่วยบริการทุก 6 ชม.</span></div></li>
        </ul>
      </div>

      <div class="pillar">
        <div class="pillar-top" style="background:linear-gradient(120deg,#23905C,#2EA36B)">
          <svg width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="9" cy="8" r="3" stroke="#fff" stroke-width="1.9"/><path d="M3.5 19c0-3 2.5-5 5.5-5s5.5 2 5.5 5" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/><circle cx="17" cy="9" r="2.4" stroke="#fff" stroke-width="1.8"/><path d="M15.5 14.2c3 0 5 1.8 5 4.8" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>
          <h3>คนและทรัพยากร<span class="cap">ดูแลคนก่อน ดูแลระบบให้พร้อม</span></h3>
        </div>
        <ul>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7" stroke="#2EA36B" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div><b>บุคลากรที่ได้รับผลกระทบ</b><span>รวมแบบสำรวจเป็นรายชื่อเดียว จัดระดับผลกระทบ ติดตาม 5 สถานะ · อัตรากำลัง RN รายเวรเทียบกรอบ</span></div></li>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20s-7-4.5-7-9.2A4.1 4.1 0 0112 8.4a4.1 4.1 0 017 2.4C19 15.5 12 20 12 20z" fill="#2EA36B"/></svg>
            <div><b>กลุ่มเปราะบาง / ศูนย์พักพิง</b><span>ติดเตียง ออกซิเจน ฟอกไต ตั้งครรภ์ เทียบกับพื้นที่ประกาศอัตโนมัติ</span></div></li>
          <li><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M13 3L5 13.5h6L10 21l8-10.5h-6z" stroke="#2EA36B" stroke-width="1.9" stroke-linejoin="round"/></svg>
            <div><b>สาธารณูปโภคและ Refer</b><span>ถังพักน้ำ ออกซิเจน น้ำมันเครื่องกำเนิดไฟฟ้า และผู้ป่วยส่งต่อช่วงอุทกภัย</span></div></li>
        </ul>
      </div>

    </div>
  </section>

  <!-- ================= เชื่อมข้อมูล + สถานะ 4 สี ================= -->
  <section class="band">
    <div class="duo">

      <div class="panel">
        <h3>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><ellipse cx="12" cy="6" rx="7.5" ry="3" stroke="#1E8FD6" stroke-width="1.9"/><path d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3" stroke="#1E8FD6" stroke-width="1.9"/></svg>
          เชื่อมข้อมูลอัตโนมัติ ลดการคีย์ซ้ำ
        </h3>
        <div class="his">
          <div>
            <div class="ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none"><path d="M4 13h3l2-5 3 10 2.5-7 1.7 3H20" stroke="#E0453A" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <p><b>HOSxP</b>ER · IPD · OPD · Refer รายวัน</p>
          </div>
          <div>
            <div class="ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.4" stroke="#1E8FD6" stroke-width="1.9"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6" stroke="#1E8FD6" stroke-width="1.9" stroke-linecap="round"/></svg></div>
            <p><b>hosoffice / Provider ID</b>ผู้ใช้งาน บุคลากร ลงเวลา</p>
          </div>
          <div>
            <div class="ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none"><rect x="4.5" y="3.5" width="15" height="17" rx="2" stroke="#2EA36B" stroke-width="1.9"/><path d="M4.5 9h15M4.5 14.5h15M10 3.5v17" stroke="#2EA36B" stroke-width="1.6"/></svg></div>
            <p><b>Google Sheet</b>แบบสำรวจ สาธารณูปโภค ศูนย์พักพิง</p>
          </div>
          <div>
            <div class="ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none"><path d="M4 20L10 4h4l6 16M12 7v2.5M12 12.5V15M12 18v2" stroke="#EE7A2B" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <p><b>กรมทางหลวง · ThaiWater</b>ถนน ระดับน้ำ ฝน</p>
          </div>
        </div>
        <p class="note">ใช้ Site API ในโรงพยาบาล อ่านข้อมูลอย่างเดียว ไม่ส่งข้อมูลรายบุคคลออกนอกระบบ · ปุ่ม “ดึงประมวล” รวบรวมข้อมูลย่อยเป็นสรุปของแต่ละตัวชี้วัดให้ SAT ตรวจก่อนบันทึก</p>
      </div>

      <div class="panel">
        <h3>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 20V5.5M5 5.5h11l-2 3.5 2 3.5H5" stroke="#1E8FD6" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
          สถานะโรงพยาบาล 4 ระดับ
        </h3>
        <div class="tiers">
          <div class="tier">
            <div class="tier-code" style="background:#2EA36B">GREEN</div>
            <div><b>ปกติ</b><span>เฝ้าระวัง รายงานเป็นระยะ</span></div>
          </div>
          <div class="tier">
            <div class="tier-code" style="background:#E3A51A">YELLOW</div>
            <div><b>เริ่มมีผลกระทบ</b><span>แจ้ง EOC ว่าอีก 6–12 ชม. โรงพยาบาลจะกระทบอะไร</span></div>
          </div>
          <div class="tier">
            <div class="tier-code" style="background:#EE7A2B">ORANGE</div>
            <div><b>กระทบระบบบริการ</b><span>เสนอทางเลือกให้ Incident Commander</span></div>
          </div>
          <div class="tier">
            <div class="tier-code" style="background:#E0453A">RED</div>
            <div><b>วิกฤต</b><span>EOC เข้าสู่ Full ICS Activation</span></div>
          </div>
        </div>
        <p class="note">ระบบเสนอสีจากข้อมูลพร้อมเหตุผล — SAT เป็นผู้ตัดสินและประกาศสถานะ</p>
      </div>

    </div>
  </section>

  <!-- ================= รอบอัปเดต ================= -->
  <section class="band">
    <div class="band-head">
      <h2>
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.2" stroke="#1E8FD6" stroke-width="2"/><path d="M12 7.5V12l3 2" stroke="#1E8FD6" stroke-width="2" stroke-linecap="round"/></svg>
        รอบการติดตามของ SAT
      </h2>
      <span class="sub">การ์ดที่เกินรอบจะขึ้นเตือน “เกินรอบ” ให้เห็นทันที</span>
    </div>
    <div class="goals">
      <div class="goal">
        <div class="fig num" style="color:#E0453A">3 ชม.</div>
        <p><b>ระดับน้ำ ถนน EMS/Refer</b>ตัวโรงพยาบาล และข้อมูลป่วยใน รพ.</p>
      </div>
      <div class="goal">
        <div class="fig num" style="color:#EE7A2B">6 ชม.</div>
        <p><b>ฝน หน่วยบริการ</b>ไฟฟ้า น้ำประปา ออกซิเจน เชื้อเพลิง IT</p>
      </div>
      <div class="goal">
        <div class="fig num" style="color:#1E8FD6">12 ชม.</div>
        <p><b>บุคลากร ผู้ป่วยเสี่ยง</b>ใครมาทำงานไม่ได้ ใครต้องย้ายก่อน</p>
      </div>
    </div>
  </section>

  <!-- ================= ระดับพื้นที่ + ประโยชน์ ================= -->
  <section class="band">
    <div class="duo">
      <div class="panel">
        <h3>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.5s-5.5 6-5.5 9.6a5.5 5.5 0 0011 0C17.5 9.5 12 3.5 12 3.5z" stroke="#1E8FD6" stroke-width="1.9"/></svg>
          ระดับพื้นที่ประกาศบนแผนที่
        </h3>
        <div class="levels">
          <?php foreach ($levels as $l) { ?>
          <span class="lv" style="--lv: <?= h($l['color']) ?>" title="<?= h(isset($l['desc']) ? $l['desc'] : '') ?>"><i></i><?= h($l['name']) ?></span>
          <?php } ?>
          <span class="lv" style="--lv: #b45309"><i></i>รอตรวจสอบ</span>
        </div>
        <p class="note">หน้าสาธารณะไม่แสดงชื่อ เบอร์โทร หรือบ้านเลขที่ของผู้แจ้ง · ข้อมูลภายในโรงพยาบาลเห็นเฉพาะผู้มีสิทธิ์ · บันทึกประวัติการแก้ไขทุกรายการ</p>
      </div>
      <div class="panel">
        <h3>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20s-7-4.5-7-9.2A4.1 4.1 0 0112 8.4a4.1 4.1 0 017 2.4C19 15.5 12 20 12 20z" fill="#1E8FD6"/></svg>
          ประโยชน์ที่ได้
        </h3>
        <div class="perks">
          <?php
          $perks = array(
              'ประชาชนรู้เส้นทางที่ผ่านไม่ได้ก่อนออกเดินทาง',
              'คำขอความช่วยเหลือไม่ตกหล่น ทุกเรื่องมีเลขและสถานะ',
              'ผู้บริหารเห็นความพร้อมโรงพยาบาลในหน้าจอเดียว',
              'บุคลากรที่ได้รับผลกระทบได้รับการติดตามครบ',
              'กลุ่มเปราะบางในพื้นที่เสี่ยงได้รับการดูแลก่อน',
              'SitRep ถึง EOC เร็วขึ้น จากข้อมูลจริงในระบบ',
          );
          foreach ($perks as $p) { ?>
          <div class="perk"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="#2EA36B"/><path d="M7.8 12.3l2.8 2.8 5.6-5.8" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg><?= h($p) ?></div>
          <?php } ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= ท้ายภาพ ================= -->
  <footer class="foot">
    <div>
      <h3>รู้สถานการณ์ รู้ความพร้อม ตัดสินใจได้ทันเวลา</h3>
      <p>ใช้งานผ่านเบราว์เซอร์ได้ทุกที่ ทั้งคอมพิวเตอร์ แท็บเล็ต และมือถือ · โทรด่วน ปภ. <?= h($ddpm) ?> · เจ็บป่วยฉุกเฉิน <?= h(EMERGENCY_PHONE) ?></p>
      <div class="pillbar">
        <span class="pill">PHP 8 · MariaDB · MVC</span>
        <span class="pill">Leaflet · OpenStreetMap</span>
        <span class="pill"><?= count($roles) ?> ระดับสิทธิ์ผู้ใช้งาน</span>
        <span class="pill">เข้าสู่ระบบด้วย Provider ID</span>
        <span class="pill">รองรับทุกอุปกรณ์</span>
      </div>
    </div>
    <div class="stamp">
      <div class="big">Sakaeo Flood</div>
      <span class="by-sm">by SCPH</span>
      <div class="sm">ระบบแจ้งจุดน้ำท่วมและห้องสถานการณ์โรงพยาบาล</div>
    </div>
  </footer>
  <div class="dev-credit">
    <span class="lbl">Developed by</span> <b>Komsan Asa</b>
    <span class="sep">·</span>
    <span>โรงพยาบาลสมเด็จพระยุพราชสระแก้ว</span>
  </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
<script>
/* ดาวน์โหลดอินโฟกราฟิกเป็น PNG — จับภาพแผ่น .sheet ที่ความกว้างเต็ม 1080px (ความละเอียด 2 เท่า) */
(function () {
    var btn = document.getElementById('abSaveImg');
    var sheet = document.getElementById('abSheet');
    if (!btn || !sheet) {
        return;
    }
    btn.addEventListener('click', function () {
        if (!window.html2canvas) {
            window.alert('กำลังโหลดเครื่องมือสร้างรูป กรุณาลองอีกครั้งในอีกสักครู่');
            return;
        }
        var label = btn.querySelector('span');
        var old = label.textContent;
        btn.disabled = true;
        label.textContent = 'กำลังสร้างรูป...';
        var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
        ready.then(function () {
            return window.html2canvas(sheet, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#EEF5FD',
                windowWidth: 1180,
                onclone: function (doc) {
                    var s = doc.getElementById('abSheet');
                    s.style.width = '1080px';
                    s.style.margin = '0';
                    s.style.borderRadius = '0';
                    s.style.boxShadow = 'none';
                }
            });
        }).then(function (canvas) {
            var a = document.createElement('a');
            a.download = 'Sakaeo-Flood-Infographic.png';
            a.href = canvas.toDataURL('image/png');
            document.body.appendChild(a);
            a.click();
            a.parentNode.removeChild(a);
        }).catch(function (e) {
            window.alert('สร้างรูปไม่สำเร็จ: ' + (e && e.message ? e.message : e));
        }).then(function () {
            btn.disabled = false;
            label.textContent = old;
        });
    });
})();
</script>
</body>
</html>
