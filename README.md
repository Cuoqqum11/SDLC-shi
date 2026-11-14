[README.md](https://github.com/user-attachments/files/34840420/README.md)
# MealDash — local food delivery platform

MealDash is a PHP and MySQL marketplace prototype for ordering food from local restaurants. It uses familiar food delivery patterns—search and categories, restaurant cards and menus, a one-restaurant cart, checkout, order progress, courier assignment, and separate operations dashboards—while keeping the existing procedural PHP and XAMPP project structure.

## XAMPP setup

1. Back up any database you need to keep from phpMyAdmin (**Export**). The app now uses a database named `food_products`; the schema does not rename, copy, or delete the old `milk_products` database.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin/`, choose **Import**, and select this repository's `schema.sql`. It creates and uses `food_products`, and adds the food-delivery tables and sample restaurants. It contains no `DROP`, `TRUNCATE`, or database rename statements. Reimporting is designed to be safe for existing rows.
4. Open `http://localhost/milk_products/`, register a customer, then browse, add menu items from a single restaurant, enter a delivery address and place a cash-on-delivery order.
5. Set up the first administrator in phpMyAdmin after registering an account. Replace the username with your own account:

   ```sql
   UPDATE users
   SET RoleID=(SELECT RoleID FROM roles WHERE RoleName='Admin')
   WHERE UserName='YOUR_USERNAME';
   ```

   Sign out and back in so the new role is picked up. The admin dashboard is at `http://localhost/milk_products/admin/`.
6. Customers who want to sell can use **Become a restaurant partner** from Account. An administrator reviews applications from **Restaurants** and approves or rejects them. Admins can also assign the Driver role in **Users**. The restaurant workspace is `restaurant.php`; courier deliveries are at `courier.php`.

If your XAMPP folder or project URL differs, adjust the URL accordingly. The app defaults to the local XAMPP MySQL account `root` with an empty password. Optional environment overrides are `FOOD_DB_HOST`, `FOOD_DB_USER`, `FOOD_DB_PASSWORD`, `FOOD_DB_NAME`, and `FOOD_DB_PORT`; the database-name default is `food_products`.

## Included functionality

- **Customers:** registration and login, restaurant and dish search, category browsing, menu and price sorting, favorites, saved delivery addresses, persistent cart, COD checkout, order history, cancellation before acceptance, status history, courier location link, and a review after delivery.
- **Restaurant owners:** partner application and approval flow, a private workspace, menu and category management, availability and stock, restaurant opening information, incoming order notifications, allowed order transitions, and basic best-selling item/order metrics.
- **Couriers:** claim an unassigned ready order, view delivery details after assignment, confirm pickup, mark the trip underway, finish a delivery, and manually send GPS coordinates while the order is active.
- **Administrators:** search/manage users and roles, activate/deactivate accounts, review restaurant applications, monitor and override an order with a recorded reason, see platform activity and dashboard metrics, and export date-filtered CSV reports for orders, revenue, restaurants, popular dishes, and users.
- **Security:** prepared SQL statements, server-side role and ownership checks, CSRF checks on state-changing forms, session cookie protections and timeout, password hashing with one-time upgrade of legacy MD5 passwords at login, image upload validation, and spreadsheet-formula-safe CSV output.

The visual layout borrows common interaction conventions seen in established delivery services, such as prominent search, category discovery, restaurant/menu cards, basket totals, clear order states, and role-specific work areas. MealDash's name, code, styling, and sample content are original. Sample dish/restaurant image URLs point to Unsplash; replace them with images you have rights to use.

## Database and existing data

`schema.sql` is the importable database setup script. It creates `food_products` and includes roles, users, categories, restaurants, menu items, addresses, carts, favorites, orders and item snapshots, status history, delivery assignments and locations, reviews, notifications, restaurant applications, and admin activity logs. It seeds starter categories and restaurants/menu items. It does not remove pre-existing tables or rows.

Importing `schema.sql` into `food_products` does not move records from a separate `milk_products` database. Back up both databases before any manual migration. Keep the old database until you have verified a separate export/import. Existing user rows already in `food_products` are preserved; old MD5 password hashes are accepted once on successful login and upgraded to `password_hash()`.

## Payment, maps, and other external services

- **Payment:** Cash on Delivery is the only checkout method enabled. No payment processor or sandbox transaction is connected. Online payment needs a chosen provider, sandbox keys, server-side payment creation, verified callbacks/webhooks, and reconciliation before it can be enabled.
- **Maps and GPS:** a courier can manually share a browser GPS point while a delivery is active. The customer can open the last point in Google Maps. There is no background tracking, live map, route/ETA service, or map SDK/API key.
- **Notifications:** in-app notifications are stored and shown. The project does not send SMS, push, or email notifications; those require provider credentials and delivery handling.

## Tests

PHP syntax checks:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & 'D:\xampp\php\php.exe' -l $_.FullName }
```

The automated database/service checks and full HTTP workflow tests require XAMPP MySQL and PHP. To keep tests away from real data, use a **separate database with `phase2_` in its name** and set `FOOD_DB_NAME` for the process. `tests/seed_fixtures.php` and `tests/run.php` refuse databases without this marker. The HTTP test expects a PHP server at `http://127.0.0.1:8765/` and uses temporary QA accounts. See [`docs/MANUAL_TEST_CHECKLIST.md`](docs/MANUAL_TEST_CHECKLIST.md) for the manual browser checks.

```powershell
$env:FOOD_DB_NAME = 'food_products_phase2_test'
& 'D:\xampp\php\php.exe' 'tests\seed_fixtures.php'
& 'D:\xampp\php\php.exe' -S 127.0.0.1:8765 -t 'D:\xampp\htdocs\milk_products'
```

In a second PowerShell window:

```powershell
$env:FOOD_DB_NAME = 'food_products_phase2_test'
& 'D:\xampp\php\php.exe' 'tests\run.php'
& '.\tests\http-smoke.ps1'
```

The fixture script prints the QA-only password. Never reuse it outside the disposable test database. Automated workflows create sample test accounts and orders in that database; recreate a fresh `phase2_` test database before repeating tests if you want a clean run.

## Project map

| Path | Purpose |
| --- | --- |
| `schema.sql` | `food_products` schema, additive upgrades, and starter catalog |
| `includes/` | Shared layout, auth/RBAC, CSRF, cart/order services, and DB connection |
| `customer/` | Storefront, menus, cart, checkout, account, notifications, and tracking source |
| `auth/` | Login, registration, and logout source |
| `restaurant/` | Restaurant owner dashboard source |
| `courier/` | Courier dashboard source |
| Root route files (`index.php`, `orders.php`, etc.) | Small compatibility entry points that keep the existing XAMPP URLs working |
| `admin/` | Admin dashboard, users, applications, orders, reports, CSV exports |
| `assets/` | Shared responsive styling and small browser interactions |
| `tests/` | Database/service tests, test fixtures, HTTP smoke workflow |
| `docs/` | Initial audit, completion report, and manual QA checklist |
| `markdown.md` | Quick cross-device handoff summary, kept at the project root |
| `legacy/` | Original unused stylesheet demo retained for reference |

The root PHP files intentionally stay as thin route wrappers. This keeps old bookmarks and internal links functional while the editable implementation is separated into feature folders.
