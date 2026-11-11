# MealDash local food delivery app

This project is a small PHP 8 + MySQL food delivery application for XAMPP. It follows familiar ordering patterns: category and keyword discovery, restaurant menu cards, one-restaurant cart, checkout, order history/status steps, merchant stock/menu tools, courier jobs, and an admin summary/export. The code is original and deliberately keeps the existing PHP project structure.

## XAMPP setup

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin/` and import `schema.sql`. The script creates and uses the `food_products` database, plus the delivery tables and sample restaurants/menu items. It does **not** drop or clear existing tables. Your previous database is left untouched.
3. Visit `http://localhost/milk_products/` and create a customer account. Browse items, add a cart, enter a delivery address, and place a cash-on-delivery order.
4. Sign in to phpMyAdmin and grant admin access only to an account you control:

   ```sql
   UPDATE users SET RoleID=(SELECT RoleID FROM roles WHERE RoleName='Admin') WHERE UserName='YOUR_USERNAME';
   ```

   Visit `/milk_products/admin/` for counts, delivered revenue, recent orders, and CSV export. Do not leave the username placeholder as written.

5. To onboard a restaurant owner, set their role and create/link a restaurant row:

   ```sql
   UPDATE users SET RoleID=(SELECT RoleID FROM roles WHERE RoleName='Restaurant') WHERE UserName='OWNER_USERNAME';
   INSERT INTO restaurants(owner_user_id,name,description,cuisine,address,delivery_fee)
   SELECT UserID,'Your Restaurant','Restaurant description','Vietnamese','Delivery address',15000
   FROM users WHERE UserName='OWNER_USERNAME';
   ```

   They can use `/milk_products/restaurant.php` to add menu items, adjust stock, and update order states.

6. To enable a courier account, set its role to `Driver` in the same way. Couriers use `/milk_products/courier.php` to claim ready orders and record GPS coordinates (browser location permission required; localhost is considered a secure context by modern browsers).

## Database

`schema.sql` is the complete import script and creates/uses the `food_products` database. It adds `categories`, `restaurants`, `menu_items`, `orders`, `order_items`, `order_status_history`, and `delivery_locations`, inserts four roles and starter content, and safely adds missing profile columns to an existing `users` table if present. It does not drop or clear any database or tables. Legacy milk inventory is not used by this app.

Database connection defaults in `db.php` are XAMPP's local `root` account with an empty password. Set `FOOD_DB_HOST`, `FOOD_DB_USER`, `FOOD_DB_PASSWORD`, and `FOOD_DB_NAME` environment variables for another local/deployed database. Never commit credentials.

## Functional scope and integrations

- Customers can register, sign in, browse/search/category-filter menu items, maintain a session cart, check out, and see order status history.
- Restaurant users can manage menu items and inventory; Admin can manage all order states. Role assignment is restricted to trusted database administration; public registration always creates a Customer.
- Couriers can claim ready orders, mark delivery complete, and record GPS coordinates. Locations are available in `delivery_locations`; this version does not include a live map tile/provider or customer-facing real-time map view.
- Cash on delivery is implemented. No payment processor is connected, and no sandbox payment success is claimed. A real Stripe/PayPal/local gateway integration needs provider selection, sandbox credentials, callback/webhook verification, and configuration before online payment should be enabled.
- Restaurant discovery/menu images use public Unsplash image URLs as sample content; replace these URLs with images you own or have permission to use.
- Search/filter and menu stock are database-backed. Admin export downloads order records as CSV.

## Existing data and backup

The import is additive. If you want to preserve data from your previous database, export a phpMyAdmin SQL backup before importing. Existing data are not deleted. Existing passwords stored as MD5 are accepted once at sign-in and upgraded to PHP password hashes. New passwords use `password_hash()`.

## Checks

Run `Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }` from this folder to check PHP syntax. Order/database behavior requires a running MySQL service and importing `schema.sql` first.
