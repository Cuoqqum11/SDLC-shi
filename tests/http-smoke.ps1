$ErrorActionPreference = 'Stop'

$base = 'http://127.0.0.1:8765/'
$dbName = $env:FOOD_DB_NAME
$fixturePassword = 'Phase2-QA-Only-2026!'
$passed = 0

if (-not $dbName -or $dbName -notlike '*phase2_*') {
    throw 'Set FOOD_DB_NAME to an isolated disposable phase2_ database before running.'
}

$fixtureRows = & 'D:\xampp\mysql\bin\mysql.exe' -u root -N -D $dbName -e "SELECT r.id,m.id FROM restaurants r JOIN menu_items m ON m.restaurant_id=r.id WHERE r.name='Saigon Kitchen' ORDER BY m.id LIMIT 1;"
if (-not $fixtureRows) {
    throw 'Run tests/seed_fixtures.php before the HTTP checks.'
}
$fixtureParts = $fixtureRows -split '\s+'
$restaurantId = [int] $fixtureParts[0]
$menuItemId = [int] $fixtureParts[1]

function Assert {
    param(
        [Parameter(Mandatory = $true)][bool] $Condition,
        [Parameter(Mandatory = $true)][string] $Label
    )

    if (-not $Condition) {
        throw "FAIL: $Label"
    }

    $script:passed++
    Write-Host "PASS: $Label"
}

function New-Session {
    return New-Object Microsoft.PowerShell.Commands.WebRequestSession
}

function Get-Page {
    param([Parameter(Mandatory = $true)][string] $Path, $Session)

    return Invoke-WebRequest -Uri ($script:base + $Path) `
        -WebSession $Session -UseBasicParsing -MaximumRedirection 8
}

function Post-Page {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        $Session,
        [Parameter(Mandatory = $true)][hashtable] $Body
    )

    return Invoke-WebRequest -Uri ($script:base + $Path) `
        -WebSession $Session -Method Post -Body $Body `
        -UseBasicParsing -MaximumRedirection 8
}

function Get-CsrfToken {
    param([Parameter(Mandatory = $true)] $Response)

    $match = [regex]::Match(
        $Response.Content,
        'name="csrf" value="([^"]+)"'
    )
    if (-not $match.Success) {
        throw 'CSRF token was not present on the page.'
    }

    return $match.Groups[1].Value
}

function Login {
    param(
        [Parameter(Mandatory = $true)][string] $Identity,
        [Parameter(Mandatory = $true)][string] $Password
    )

    $session = New-Session
    $page = Get-Page 'login.php' $session
    $body = @{
        csrf     = Get-CsrfToken $page
        identity = $Identity
        password = $Password
    }
    $response = Post-Page 'login.php' $session $body

    return @{
        session  = $session
        response = $response
    }
}

# Customer registration, authentication, restaurant discovery, and cart.
$customer = 'qa_phase2_' + [guid]::NewGuid().ToString('N').Substring(0, 8)
$session = New-Session
$page = Get-Page 'register.php' $session
$body = @{
    csrf      = Get-CsrfToken $page
    full_name = 'Phase Two QA Customer'
    username  = $customer
    email     = "$customer@example.test"
    phone     = '0900000011'
    password  = 'QA-Customer-Only-123!'
}
$response = Post-Page 'register.php' $session $body
Assert ($response.Content -match 'Sign in to MealDash') `
    'Customer registration completes and redirects to sign-in'

$duplicate = Post-Page 'register.php' $session @{
    csrf      = Get-CsrfToken $response
    full_name = 'Duplicate Customer'
    username  = $customer
    email     = 'another@example.test'
    phone     = '0900000012'
    password  = 'QA-Customer-Only-123!'
}
Assert ($duplicate.Content -match 'already in use') 'Duplicate username is rejected'

$badLogin = Login $customer 'wrong-password'
Assert ($badLogin.response.Content -match 'incorrect') 'Incorrect password is rejected'

$goodLogin = Login $customer 'QA-Customer-Only-123!'
$customerSession = $goodLogin.session
Assert ($goodLogin.response.Content -match 'Discover local food') `
    'Customer login opens the storefront'

$homePage = Get-Page 'index.php?q=Grilled' $customerSession
Assert ($homePage.Content -match 'Saigon Kitchen') `
    'Restaurant and dish search finds a matching restaurant'

$detail = Get-Page "restaurant_detail.php?id=$restaurantId" $customerSession
Assert ($detail.Content -match 'Menu' -and $detail.Content -match 'Grilled pork rice bowl') `
    'Restaurant detail shows its menu and item prices'

$token = Get-CsrfToken $detail
$null = Post-Page 'index.php' $customerSession @{
    csrf     = $token
    item_id  = "$menuItemId"
    quantity = '1'
    add_item = 'Add'
}
$cart = Get-Page 'orders.php' $customerSession
Assert ($cart.Content -match 'Your cart' -and $cart.Content -match 'Order summary') `
    'Customer can add food and open the cart'

$token = Get-CsrfToken $cart
$null = Post-Page 'orders.php' $customerSession @{
    csrf        = $token
    item_id     = "$menuItemId"
    quantity    = '2'
    cart_action = 'update'
}
$cart = Get-Page 'orders.php' $customerSession
Assert ($cart.Content -match 'value="2"') `
    'Cart quantity updates and totals use database prices'

$token = Get-CsrfToken $cart
$null = Post-Page 'orders.php' $customerSession @{
    csrf        = $token
    item_id     = "$menuItemId"
    quantity    = '0'
    cart_action = 'remove'
}
$cart = Get-Page 'orders.php' $customerSession
Assert ($cart.Content -match 'Your cart is waiting') `
    'Customer can remove a cart item and sees an empty state'

# Place an order and verify customer ownership boundaries.
$detail = Get-Page "restaurant_detail.php?id=$restaurantId" $customerSession
$null = Post-Page 'index.php' $customerSession @{
    csrf     = Get-CsrfToken $detail
    item_id  = "$menuItemId"
    quantity = '1'
    add_item = 'Add'
}

$cart = Get-Page 'orders.php' $customerSession
$checkout = Post-Page 'orders.php' $customerSession @{
    csrf           = Get-CsrfToken $cart
    address_id     = '0'
    recipient_name = 'QA Recipient'
    recipient_phone = '0900000033'
    address        = '123 Test Street, District 1'
    note           = 'Please call at the gate'
    save_address   = '1'
    checkout       = '1'
}
Assert ($checkout.Content -match 'Order progress' -and $checkout.Content -match 'Waiting restaurant') `
    'Cash-on-delivery checkout creates and tracks an order'

$orderMatch = [regex]::Match(
    $checkout.BaseResponse.ResponseUri.AbsoluteUri,
    'order_detail\.php\?id=(\d+)'
)
if (-not $orderMatch.Success) {
    $orderMatch = [regex]::Match($checkout.Content, 'Order #([0-9]+)')
}
if (-not $orderMatch.Success) {
    throw 'Could not identify the created order.'
}
$orderId = [int] $orderMatch.Groups[1].Value

$secondCustomer = Login 'qa_other' $fixturePassword
$idorBlocked = $false
try {
    $intruderPage = Get-Page "order_detail.php?id=$orderId" $secondCustomer.session
    $idorBlocked = $intruderPage.StatusCode -eq 404
} catch {
    $idorBlocked = $_.Exception.Response.StatusCode.value__ -eq 404
}
Assert $idorBlocked "Customer cannot access another customer's order by changing its ID"

$blockedPage = Get-Page 'admin/index.php' $customerSession
Assert ($blockedPage.Content -match 'Sign in to MealDash') `
    'Customer cannot access the admin dashboard'

# Merchant receives the order and advances it through the allowed states.
$restaurantLogin = Login 'qa_restaurant' $fixturePassword
$restaurantSession = $restaurantLogin.session
$restaurantPage = Get-Page 'restaurant.php' $restaurantSession
Assert ($restaurantPage.Content -match 'Order management' -and $restaurantPage.Content -match "Order #$orderId") `
    'Restaurant sees its incoming customer order'

foreach ($nextStatus in @('Accepted', 'Preparing', 'Ready for pickup')) {
    $restaurantPage = Get-Page 'restaurant.php' $restaurantSession
    $null = Post-Page 'restaurant.php' $restaurantSession @{
        csrf        = Get-CsrfToken $restaurantPage
        action      = 'order_status'
        order_id    = "$orderId"
        next_status = $nextStatus
        reason      = 'QA status test'
    }
}
$restaurantPage = Get-Page 'restaurant.php' $restaurantSession
Assert ($restaurantPage.Content -match 'Ready for pickup') `
    'Restaurant advances through accepted, preparing, and ready states'

# Courier claims the delivery, shares a location, and completes the order.
$driverLogin = Login 'qa_driver' $fixturePassword
$driverSession = $driverLogin.session
$driverPage = Get-Page 'courier.php' $driverSession
$null = Post-Page 'courier.php' $driverSession @{
    csrf     = Get-CsrfToken $driverPage
    order_id = "$orderId"
    claim    = 'Accept delivery'
}
$driverPage = Get-Page 'courier.php' $driverSession
Assert ($driverPage.Content -match 'YOUR DELIVERY' -and $driverPage.Content -match 'QA Recipient') `
    'Courier claims a ready delivery and receives its destination details'

foreach ($action in @('picked_up', 'on_the_way')) {
    $driverPage = Get-Page 'courier.php' $driverSession
    $body = @{
        csrf     = Get-CsrfToken $driverPage
        order_id = "$orderId"
    }
    $body[$action] = '1'
    $null = Post-Page 'courier.php' $driverSession $body
}

$driverPage = Get-Page 'courier.php' $driverSession
$null = Post-Page 'courier.php' $driverSession @{
    csrf      = Get-CsrfToken $driverPage
    order_id  = "$orderId"
    location  = '1'
    latitude  = '10.7769'
    longitude = '106.7009'
}
$customerSession = (Login $customer 'QA-Customer-Only-123!').session
$customerDetail = Get-Page "order_detail.php?id=$orderId" $customerSession
Assert ($customerDetail.Content -match 'View latest courier location' -and $customerDetail.Content -match 'On the way') `
    'Customer sees active status and latest courier GPS link'

$driverPage = Get-Page 'courier.php' $driverSession
$null = Post-Page 'courier.php' $driverSession @{
    csrf     = Get-CsrfToken $driverPage
    order_id = "$orderId"
    deliver  = 'Complete delivery'
}
$customerDetail = Get-Page "order_detail.php?id=$orderId" $customerSession
Assert ($customerDetail.Content -match 'Delivered' -and $customerDetail.Content -match 'Leave a review') `
    'Customer sees completion and eligible review action'

$reviewPage = Get-Page "restaurant_detail.php?id=$restaurantId" $customerSession
$invalidReview = Post-Page "restaurant_detail.php?id=$restaurantId" $customerSession @{
    csrf     = Get-CsrfToken $reviewPage
    action   = 'review'
    order_id = "$orderId"
    rating   = '99'
    comment  = 'Invalid QA rating'
}
Assert ($invalidReview.Content -match 'Choose a rating from 1 to 5') `
    'Out-of-range review ratings are rejected'

$reviewPage = Get-Page "restaurant_detail.php?id=$restaurantId" $customerSession
$review = Post-Page "restaurant_detail.php?id=$restaurantId" $customerSession @{
    csrf     = Get-CsrfToken $reviewPage
    action   = 'review'
    order_id = "$orderId"
    rating   = '5'
    comment  = 'Great QA meal'
}
Assert ($review.Content -match 'Ratings & reviews' -and $review.Content -match 'Great QA meal') `
    'Completed-order review is accepted and displayed'

# Check the administration pages and spreadsheet export.
$adminLogin = Login 'qa_admin' $fixturePassword
$adminSession = $adminLogin.session
$dashboard = Get-Page 'admin/index.php' $adminSession
Assert ($dashboard.Content -match 'Delivered revenue' -and $dashboard.Content -match 'Orders by day') `
    'Admin dashboard uses database-backed metrics and charts'

$usersPage = Get-Page "admin/users.php?q=$customer" $adminSession
Assert ($usersPage.Content -match $customer) 'Admin can search for an account'

$restaurantsPage = Get-Page 'admin/restaurants.php' $adminSession
Assert ($restaurantsPage.Content -match 'Registered restaurants') `
    'Admin can review and manage restaurants'

$ordersPage = Get-Page "admin/orders.php?q=$orderId" $adminSession
Assert ($ordersPage.Content -match "Order #$orderId") 'Admin can search and inspect an order'

$csv = Get-Page "admin/export.php?type=orders&from=2020-01-01" $adminSession
Assert ($csv.Headers['Content-Type'] -match 'text/csv' -and $csv.Content -match "(?m)^$orderId,") `
    'Filtered orders CSV export downloads successfully'

$reportsPage = Get-Page 'admin/reports.php' $adminSession
Assert ($reportsPage.Content -match 'Restaurant performance' -and $reportsPage.Content -match 'Popular dishes') `
    'Admin reports include restaurant and dish exports'

$accountPage = Get-Page 'account.php' $customerSession
Assert ($accountPage.Content -match 'Saved addresses' -and $accountPage.Content -match 'Favorite restaurants') `
    'Customer account exposes addresses and saved restaurants'

$null = Post-Page "restaurant_detail.php?id=$restaurantId" $customerSession @{
    csrf   = Get-CsrfToken $accountPage
    action = 'favorite'
}
$accountPage = Get-Page 'account.php' $customerSession
Assert ($accountPage.Content -match 'Saigon Kitchen') 'Customer can save a favorite restaurant'

$notificationsPage = Get-Page 'notifications.php' $customerSession
Assert ($notificationsPage.Content -match 'Notifications') 'Customer can read order notifications'

Write-Host "All $passed HTTP workflow checks passed for order #$orderId."
