# 🚗 Fleet & HR Tracking System

> A unified operations platform for companies with field staff and drivers — live GPS tracking, location-stamped attendance, full HR management, inventory, and supplier tracking. Runs as an installable PWA on staff phones.

---

## 🧠 What This System Does

Most companies use separate tools for HR and fleet management. This system unifies everything in one platform: the driver opens the app on their phone, starts a trip, and the admin watches in real time — distance split by working hours vs. off-hours, live location, speed, and geofence alerts. At the same time, office employees clock in with a GPS stamp, submit leave requests, and track their performance — all from the same system.

---

## ✨ Core Features

### 🗺️ Live Fleet Tracking
- Real-time GPS tracking for all active vehicles on one dashboard
- Trips automatically split into **inside-hours** and **outside-hours** kilometers
- **Geofence alerts** — instant notification when a vehicle leaves its defined zone
- **GPS kill detection** — alert fires within 10 minutes if a driver turns off location
- Speed tracking with `recorded_at` timestamps per location point
- Full trip history with purpose, distance, duration, and route

### 🧑‍💼 HR Management
- **Attendance** — GPS-stamped check-in/check-out with Arabic address (Google Geocoding API)
- **Late tracking** — configurable grace period, automatic late-minutes calculation
- **Leave management** — request, approve/reject, balance tracking per leave type
- **Leave balance** — auto-created per employee per year with configurable defaults
- **Overtime requests** — employee submits, manager approves
- **Force checkout** — admin can close an employee's shift remotely
- **Shifts** — per-employee work start/end times
- **Performance reports** — per-employee attendance and punctuality summary
- **Notes** — admin can attach private notes to employee profiles
- **Holidays** — official holidays excluded from business-day calculations

### 👷 Driver-Specific App
- Separate mobile-optimized view for drivers
- Task management — accept, start, complete, or cancel assigned tasks
- Fuel log — driver submits fuel fill-ups with station and cost
- Live trip start/end with automatic distance calculation

### 🔧 Vehicle Management
- Vehicle registry with plate numbers and status
- **Maintenance log** — oil changes, tires, brakes, filters, battery, inspections
- **Fuel log** — per-vehicle cost and liters with odometer tracking
- Next service date and km reminders
- Odometer tracking per fill-up

### 📦 Inventory & Warehouse
- Parts catalog with barcode, cost price, sell price, and section
- Stock level tracking with configurable minimum quantity alerts
- Low-stock and out-of-stock filters
- Inventory log — full audit trail of every quantity change with reason
- Profit margin calculation per part (sell price vs. cost price)
- Image support per part
- Supplier linking per part

### 🏭 Supplier Management
- Supplier registry with contact information
- Linked to inventory parts for traceability

### 📊 Reports & Exports
- Attendance export (Excel)
- Per-employee performance report
- Fleet trip summary (inside vs. outside hours, total km)
- Inventory reports with stock value

### 🔒 Security & Roles
- Role-based access: **Admin**, **HR**, **Employee**, **Driver**
- Drivers redirected to driver-only view
- Admin-only endpoints enforced at API level
- Session-based authentication with middleware
- All secrets in `.env` — nothing hardcoded

### 📱 PWA
- Installable on Android and iOS (add to home screen)
- Service worker for offline shell
- Push-ready notification infrastructure

---

## 🛠️ Tech Stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.x |
| Database | MySQL |
| Maps | Google Maps Geocoding API |
| GPS | Browser Geolocation API |
| Frontend | Vanilla JS + PWA (service worker) |
| Cron | PHP CLI scheduled jobs (every 5 min) |

---

## 📁 Project Structure

```
├── api/
│   ├── attendance.php           # Check-in/out with GPS + address
│   ├── trip_live.php            # Live trips dashboard data
│   ├── update_location.php      # Driver location update endpoint
│   ├── update_trip_location.php # Trip-specific location tracking
│   ├── location_alert.php       # GPS-off alert trigger
│   ├── fleet_api.php            # Driver tasks + geofence check
│   ├── fleet_api_additions.php  # Fuel log + maintenance log
│   ├── overtime_request.php     # Overtime request submission
│   ├── force_checkout.php       # Admin force-checkout endpoint
│   ├── export_attendance.php    # Excel attendance export
│   ├── inventory_api.php        # Parts catalog CRUD + stock log
│   ├── suppliers_api.php        # Supplier management
│   ├── finance_api.php          # Finance-related endpoints
│   └── notif_api.php            # Push notification API
│
├── includes/
│   ├── config.php               # .env loader + DB singleton + constants
│   ├── auth.php                 # Session auth + role helpers
│   ├── hr_helpers.php           # Leave balance, business-day calc, notifications
│   ├── theme.php                # Shared HTML layout
│   └── upload_helper.php        # File upload handler
│
├── driver.php                   # Mobile-optimized driver app
├── trips_live.php               # Real-time fleet map dashboard
├── vehicles.php                 # Vehicle registry and management
├── fleet.php                    # Fleet overview
├── employees.php                # Employee list and management
├── employee.php                 # Single employee profile
├── employee_report.php          # Per-employee performance report
├── attendance.php               # Attendance records view
├── shifts.php                   # Shift configuration
├── leaves.php                   # Leave requests and approvals
├── performance.php              # Performance dashboard
├── messages.php                 # Internal messaging
├── notifications.php            # Notification center
├── inventory.php                # Parts inventory management
├── inventory_reports.php        # Inventory reports
├── warehouse_dashboard.php      # Warehouse overview
├── suppliers.php                # Supplier management
├── reports.php                  # General reports
├── admin.php                    # Admin panel
├── cron.php                     # Main cron job (runs every 5 min)
├── cron_fleet.php               # Fleet cron — geofence checks, alerts
└── login.php                    # Authentication
```

---

## ⚙️ Setup

### 1. Clone & configure

```bash
git clone https://github.com/yazanhamde/fleet-hr-tracking
cd fleet-hr-tracking
cp .env.example .env
```

### 2. Fill in `.env`

```env
DB_HOST=localhost
DB_USER=your_db_user
DB_PASS=your_db_password
DB_NAME=your_db_name

SITE_URL=https://yourdomain.com/tracking
GMAPS_API_KEY=your_google_maps_api_key

WORK_START=10:00
WORK_END=21:00
LATE_GRACE_MINUTES=10
```

### 3. Set up cron jobs

```bash
# Every 5 minutes — geofence checks, GPS alerts
*/5 * * * * php /path/to/cron.php
```

### 4. Register webhook on Meta (if using WhatsApp alerts)

```
https://yourdomain.com/api/notif_api.php
```

---

## 🗄️ Database

The schema covers **30+ tables** including:

- Employees with role, shift, and status
- Attendance with GPS coordinates and reverse-geocoded address
- Vehicles with geofence settings and odometer
- Vehicle tracking (per-point GPS log with speed and timestamp)
- Trips with inside/outside hours and km split
- Driver tasks with status lifecycle
- Vehicle fuel log and maintenance history
- Leave types, balances, and requests
- Overtime requests
- Alerts (GPS-off, geofence breach)
- Parts inventory with sections and stock log
- Suppliers
- Holidays
- Notifications

> Full schema available on request.

---

## 📊 Production Stats

- Used daily by a company with drivers and field staff in Jordan
- Drivers interact entirely through the mobile PWA
- Admin monitors live trips and receives instant GPS-off alerts
- Attendance exported to Excel monthly for payroll processing
- Geofence breaches logged automatically every 5 minutes

---

## 👤 Author

**Yazan Hamdi** — Full Stack AI Engineer  
[github.com/yazanhamde](https://github.com/yazanhamde) · [goldensketch.com/yazan-hamdi-demo](https://goldensketch.com/yazan-hamdi-demo/)
