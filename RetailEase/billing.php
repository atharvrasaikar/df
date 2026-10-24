
<?php

require_once __DIR__ . '/includes/db.php';

// Retrieve products that are currently in stock.
try {
    $productQuery = $pdo->query(
        "SELECT id, name, price, stock
         FROM products
         WHERE stock > 0
         ORDER BY name"
    );

    $products = $productQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $error) {
    error_log('Billing page error: ' . $error->getMessage());

    http_response_code(500);
    exit('Unable to load products. Please check the database connection.');
}

// Retrieve the most recent bills for the bill history section.
$historyUnavailable = false;

try {
    $historyQuery = $pdo->query(
        "SELECT id, total, cgst, sgst, grand_total, created_at
         FROM bills
         ORDER BY id DESC
         LIMIT 20"
    );

    $recentBills = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $error) {
    // Allow the billing page to load even if bill history is unavailable.
    error_log('Bill history error: ' . $error->getMessage());

    $recentBills = [];
    $historyUnavailable = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>ShopBill - Billing</title>

    <!-- Reuse the project's shared stylesheet. -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
<main class="billing-container">

    <h1>ShopBill</h1>
    <h2>Create a Bill</h2>

    <!-- Display success and error messages here. -->
    <p id="billingMessage" role="status" aria-live="polite"></p>

    <!-- Product selection section. -->
    <section class="billing-controls">

        <label for="productSelect">Select Product:</label>

        <select id="productSelect">
            <option value="">-- Select a product --</option>

            <?php foreach ($products as $product): ?>
                <option
                    value="<?= (int) $product['id'] ?>"
                    data-name="<?= htmlspecialchars(
                        (string) $product['name'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    data-price="<?= htmlspecialchars(
                        (string) $product['price'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    data-stock="<?= (int) $product['stock'] ?>"
                >
                    <?= htmlspecialchars(
                        (string) $product['name'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                    - ₹<?= number_format(
                        (float) $product['price'],
                        2
                    ) ?>

                    (Stock: <?= (int) $product['stock'] ?>)
                </option>
            <?php endforeach; ?>

        </select>

        <label for="quantity">Quantity:</label>

        <input
            type="number"
            id="quantity"
            min="1"
            step="1"
            value="1"
        >

        <button type="button" id="addProduct">
            Add Product
        </button>

    </section>

    <!-- The JavaScript inserts the cart items into this table. -->
    <section>
        <h3>Current Bill</h3>

        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Unit Price</th>
                    <th>Quantity</th>
                    <th>Subtotal</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody id="billingCart">
            </tbody>
        </table>
    </section>


    <section class="billing-totals">

        <p>
            Subtotal: ₹<span id="billingSubtotal">0.00</span>
        </p>

        <p>
            CGST (9%): ₹<span id="billingCGST">0.00</span>
        </p>

        <p>
            SGST (9%): ₹<span id="billingSGST">0.00</span>
        </p>

        <h3>
            Grand Total: ₹<span id="billingGrandTotal">0.00</span>
        </h3>

    </section>


    <button type="button" id="saveBill">
        Create Bill
    </button>

    
    <section class="bill-history">
        <h2>Recent Bills</h2>

        <?php if ($historyUnavailable): ?>

            <p>
                Bill history is unavailable.
                Please check the database tables.
            </p>

        <?php elseif (empty($recentBills)): ?>

            <p>No bills have been created yet.</p>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Bill ID</th>
                        <th>Date</th>
                        <th>Subtotal</th>
                        <th>Grand Total</th>
                        <th>Invoice</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($recentBills as $bill): ?>
                        <tr>
                            <td>
                                <?= (int) $bill['id'] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    (string) $bill['created_at'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float) $bill['total'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float) $bill['grand_total'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <a
                                    href="invoice.php?id=<?= (int) $bill['id'] ?>"
                                >
                                    View Invoice
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>
    </section>

</main>

<!-- Load the separate billing JavaScript file. -->
<script src="assets/js/billing.js"></script>

</body>
</html>
