<?php
// เข้าสู่ระบบด้วย Provider ID (Health ID / หมอพร้อม) — คัดลอกเป็น config/provider_id.php แล้วกรอกค่า
// ค่า client ชุดเดียวกับระบบ sysinspec · ต้องลงทะเบียน redirect_uri กับ Health ID:
//   https://scph-app.moph.go.th/Flood2026/login/providerCallback
return array(
    'enabled' => false,
    'health_client_id' => '',
    'health_client_secret' => '',
    'provider_client_id' => '',
    'provider_secret_key' => '',
    'redirect_uri' => '',          // เว้นว่าง = URL . 'login/providerCallback'
    'ssl_verify' => true,
    'curl_resolve' => '',
    // ผู้ที่สังกัด hcode นี้ได้สิทธิ์ตามบัญชี (บัญชีใหม่เริ่มที่ ผู้บริหาร/ดูอย่างเดียว ผู้ดูแลปรับให้ได้)
    // ผู้ที่ไม่ได้สังกัด hcode นี้ เข้าได้เฉพาะ ผู้บริหาร/ดูอย่างเดียว เสมอ
    'home_hcodes' => array('10699'),
    // ใช้แปลงเลขบัตรเป็นรหัสอ้างอิงก่อนเก็บ (ไม่เก็บเลขบัตรจริง) — ห้ามเปลี่ยนหลังเปิดใช้ ไม่งั้นบัญชีเดิมจะผูกไม่ติด
    'cid_salt' => '',
);
