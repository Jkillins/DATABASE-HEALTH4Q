# Health4Q - PHP Backend Setup (Local XAMPP)

This project contains a minimal PHP backend and SQL schema to run the Health4Q static site with a MySQL database. It includes example API endpoints demonstrating CRUD, JOINs, subqueries, UNION and transactions.

Files added:
- `db/schema.sql` - creates `health4q` database, tables and sample data
- `db.php` - PDO helper
- `api/appointments.php` - appointments CRUD + JOIN + transaction example
- `api/patients.php` - patients CRUD + subquery example
- `api/doctors.php` - doctors CRUD + UNION example

Quick steps to run with XAMPP (Windows):

1. Copy this project into your XAMPP `htdocs` folder (already in `C:\xampp\htdocs\DATABASE-RAMA\Health4q\Health4Q`).
2. Start XAMPP Control Panel as Administrator and start **Apache** and **MySQL**.
3. Open `http://localhost/phpmyadmin` and import the SQL schema:
   - Click `Import` → choose `db/schema.sql` and run. This will create the `health4q` database and sample data.
4. Edit DB credentials if needed in `db.php` (default `root` user, empty password).
5. Open the site: `http://localhost/Health4Q/index.html` (or the appropriate folder name).

API usage examples (browser or Postman):

- List appointments (with JOIN):
  `GET http://localhost/Health4Q/api/appointments.php?action=list`

- Create appointment (transaction demo):
  `POST http://localhost/Health4Q/api/appointments.php?action=create`
  Body (form-data or x-www-form-urlencoded): `patient_id`, `doctor_id`, `scheduled_at` (YYYY-MM-DD HH:MM:SS), `notes`

- List patients (subquery example):
  `GET http://localhost/Health4Q/api/patients.php?action=list`

- Create doctor (union demo uses `doctors.php?action=search_union`):
  `POST http://localhost/Health4Q/api/doctors.php?action=create`
  Body: `name`, `email`, `specialty`, `clinic`

Notes & next steps
- The current front-end pages are static HTML; to fully integrate, update form `action` attributes to point to the API endpoints (examples are provided above). I can patch specific pages' forms to submit to the backend.
- If your MySQL root user has a password, set it in `db.php`.
- If you want, I can:
  - Patch the appointment form in `patientappoint.html` to POST to the `appointments.php` API.
  - Add server-side validation and authentication.
  - Wire other pages (medical-data request, issuance) to the backend.
