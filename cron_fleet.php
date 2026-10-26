<?php
/**
 * cron_fleet.php — مهام مجدولة للأسطول
 * يُشغَّل كل 5 دقائق مع cron.php الرئيسي
 *
 * إضافة إلى cron.php:
 *   require_once __DIR__.'/cron_fleet.php';
 *   runFleetCron($db);
 */

function runFleetCron(PDO $db): void
{
    // ── 1. فحص الجيوفنس لكل سيارة نشطة ─────────────────
    $activeVehicles = $db->query("
        SELECT vt.vehicle_id, vt.lat, vt.lng, vt.employee_id,
               v.name, v.geofence_lat, v.geofence_lng, v.geofence_radius_m
        FROM vehicle_tracking vt
        JOIN vehicles v ON v.id = vt.vehicle_id
        WHERE v.geofence_active = 1
          AND v.geofence_lat IS NOT NULL
          AND vt.id IN (SELECT MAX(id) FROM vehicle_tracking GROUP BY vehicle_id)
          AND v.status = 'active'
    ")->fetchAll();

    foreach ($activeVehicles as $veh) {
        $R  = 6371000;
        $φ1 = deg2rad($veh['lat']);
        $φ2 = deg2rad($veh['geofence_lat']);
        $dφ = deg2rad($veh['geofence_lat'] - $veh['lat']);
        $dλ = deg2rad($veh['geofence_lng'] - $veh['lng']);
        $a  = sin($dφ/2)**2 + cos($φ1)*cos($φ2)*sin($dλ/2)**2;
        $dist = 2 * $R * asin(sqrt($a));

        if ($dist > $veh['geofence_radius_m']) {
            // تحقق من عدم وجود تنبيه حديث
            $recent = $db->prepare("
                SELECT id FROM geofence_alerts
                WHERE vehicle_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                LIMIT 1
            ");
            $recent->execute([$veh['vehicle_id']]);
            if (!$recent->fetch()) {
                $db->prepare("
                    INSERT INTO geofence_alerts (vehicle_id, driver_id, alert_type, lat, lng, distance_m)
                    VALUES (?,?,'exit',?,?,?)
                ")->execute([$veh['vehicle_id'], $veh['employee_id'], $veh['lat'], $veh['lng'], intval($dist)]);

                $db->prepare("
                    INSERT INTO alerts (type, vehicle_id, employee_id, message)
                    VALUES ('geofence_exit',?,?,?)
                ")->execute([
                    $veh['vehicle_id'],
                    $veh['employee_id'],
                    "🚨 السيارة {$veh['name']} خارج نطاق الجيوفنس! المسافة: ".intval($dist)." م"
                ]);
            }
        }
    }

    // ── 2. تنبيهات الصيانة القادمة (يومياً) ─────────────
    // شغّل مرة واحدة فقط بين 8:00–8:05 صباحاً
    if ((int)date('G') === 8 && (int)date('i') < 5) {
        $needsMaint = $db->query("
            SELECT id, name, odometer_km, next_service_km, next_service_date
            FROM vehicles
            WHERE (next_service_km IS NOT NULL AND odometer_km >= (next_service_km - 300))
               OR (next_service_date IS NOT NULL AND next_service_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
        ")->fetchAll();

        foreach ($needsMaint as $v) {
            // تحقق أنه لم يُرسل تنبيه اليوم لهذه السيارة
            $todayAlert = $db->prepare("
                SELECT id FROM alerts
                WHERE type='maintenance_due' AND vehicle_id=?
                  AND DATE(created_at) = CURDATE()
                LIMIT 1
            ");
            $todayAlert->execute([$v['id']]);
            if (!$todayAlert->fetch()) {
                $msg = "🔧 السيارة {$v['name']} تحتاج صيانة";
                if ($v['next_service_date'] && strtotime($v['next_service_date']) <= strtotime('+7 days')) {
                    $msg .= ' — الموعد: '.date('d/m/Y', strtotime($v['next_service_date']));
                }
                if ($v['next_service_km'] && $v['odometer_km'] >= ($v['next_service_km'] - 300)) {
                    $remaining = $v['next_service_km'] - $v['odometer_km'];
                    $msg .= ' — تبقى '.max(0,$remaining).' كم';
                }
                $db->prepare("
                    INSERT INTO alerts (type, vehicle_id, message)
                    VALUES ('maintenance_due',?,?)
                ")->execute([$v['id'], $msg]);
            }
        }
    }

    // ── 3. تنبيه المهام المتأخرة ─────────────────────────
    $overdueTasks = $db->query("
        SELECT t.id, t.driver_id, t.title, t.due_date, t.due_time
        FROM driver_tasks t
        WHERE t.status IN ('pending','accepted')
          AND t.due_date IS NOT NULL
          AND CONCAT(t.due_date,' ',COALESCE(t.due_time,'23:59:00')) < NOW()
    ")->fetchAll();

    foreach ($overdueTasks as $task) {
        $existing = $db->prepare("
            SELECT id FROM notifications
            WHERE employee_id=? AND body LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT 1
        ");
        $existing->execute([$task['driver_id'], "%{$task['id']}%"]);
        if (!$existing->fetch()) {
            $db->prepare("
                INSERT INTO notifications (employee_id, title, body, type)
                VALUES (?,?,?,'task')
            ")->execute([
                $task['driver_id'],
                '⚠️ مهمة متأخرة',
                "المهمة #{$task['id']}: {$task['title']} — تجاوزت موعدها"
            ]);

            $db->prepare("
                INSERT INTO alerts (type, employee_id, message)
                VALUES ('task_overdue',?,?)
            ")->execute([
                $task['driver_id'],
                "⏰ المهمة «{$task['title']}» لم تُنجز في موعدها"
            ]);
        }
    }
}
