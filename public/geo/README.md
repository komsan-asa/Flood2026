# ขอบเขตอำเภอ (public/geo/amphoe/<รหัสอำเภอ 4 หลัก>.json)

ใช้ลงสีพื้นหลังของอำเภอที่เลือกบนแผนที่ประชาชน (views/index/js/default.js → showAreaShape)

- ที่มา: ชั้นข้อมูลขอบเขตอำเภอ 928 อำเภอ `districts.geojson` จาก OpenGISData-Thailand
  https://github.com/chingchai/OpenGISData-Thailand (ดึงเมื่อ 28 ก.ย. 69)
- แปลงแล้ว: 1 ไฟล์ต่ออำเภอ (GeoJSON Feature · properties = code, name, province) ปัดพิกัด 5 ตำแหน่ง (~1 ม.)
- เส้นขอบเป็นแบบย่อ (โดยประมาณ) เหมาะสำหรับแสดงพื้นหลัง ไม่ใช่แนวเขตทางกฎหมาย
- อำเภอที่ไม่มีไฟล์ (เช่นรหัสท้องถิ่นพิเศษ 7074, 9077) = แผนที่ไม่ลงสี
