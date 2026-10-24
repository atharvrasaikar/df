
<?php

require_once __DIR__ . '/includes/db.php';


function shopBillInvoiceEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function shopBillInvoiceMoney($value): string
{
    return number_format((float) $value, 2);
}

// Read the invoice ID from the URL.
$billId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

// Reject an invalid invoice ID.
if (!$billId || $billId < 1) {
    http_response_code(400);
    exit('Invalid invoice ID.');
}

// Retrieve the saved bill summary.
$billQuery = $pdo->prepare(
    "SELECT id, total, cgst, sgst, grand_total, created_at
     FROM bills
     WHERE id = ?"
);

$billQuery->execute([$billId]);

$bill = $billQuery->fetch(PDO::FETCH_ASSOC);

// Display an error if the bill does not exist.
if (!$bill) {
    http_response_code(404);
    exit('Invoice not found.');
}

// Retrieve all products included in this bill.
$itemQuery = $pdo->prepare(
    "SELECT
        bi.product_id,
        p.name AS product_name,
        bi.quantity,
        bi.unit_price,
        bi.subtotal
     FROM bill_items AS bi
     LEFT JOIN products AS p
        ON p.id = bi.product_id
     WHERE bi.bill_id = ?
     ORDER BY bi.id"
);

$itemQuery->execute([$billId]);

$items = $itemQuery->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        ShopBill Invoice #<?= (int) $bill['id'] ?>
    </title>

    <!-- Reuse the project's shared stylesheet. -->
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        /* Basic invoice layout. */
        .invoice-container {
            max-width: 850px;
            margin: 30px auto;
            padding: 24px;
            background: #ffffff;
        }

        .invoice-header {
            text-align: center;
        }

        .invoice-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }

        .invoice-table th,
        .invoice-table td {
            padding: 10px;
            border-bottom: 1px solid #dddddd;
        }

        .invoice-table th:not(:first-child),
        .invoice-table td:not(:first-child) {
            text-align: right;
        }

        .invoice-totals {
            margin-top: 20px;
            text-align: right;
        }

        /* Hide action buttons when printing. */
        @media print {
            .no-print {
                display: none !important;
            }

            .invoice-container {
                max-width: none;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>

<body>
<main class="invoice-container">

    <!-- Invoice heading. -->
    <header class="invoice-header">
        <h1>SHOPBILL</h1>
        <p>Retail Billing and Inventory System</p>
        <h2>INVOICE</h2>
    </header>

    <!-- Bill identification and date. -->
    <section>
        <p>
            <strong>Invoice Number:</strong>
            <?= (int) $bill['id'] ?>
        </p>

        <p>
            <strong>Date:</strong>
            <?= shopBillInvoiceEscape($bill['created_at']) ?>
        </p>
    </section>

    <!-- Products included in the invoice. -->
    <table class="invoice-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Unit Price</th>
                <th>Quantity</th>
                <th>Amount</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?= shopBillInvoiceEscape(
                            $item['product_name'] ?? 'Product unavailable'
                        ) ?>
                    </td>

                    <td>
                        ₹<?= shopBillInvoiceMoney(
                            $item['unit_price']
                        ) ?>
                    </td>

                    <td>
                        <?= (int) $item['quantity'] ?>
                    </td>

                    <td>
                        ₹<?= shopBillInvoiceMoney(
                            $item['subtotal']
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Display the totals stored when the bill was created. -->
    <section class="invoice-totals">

        <p>
            Subtotal:
            ₹<?= shopBillInvoiceMoney($bill['total']) ?>
        </p>

        <p>
            CGST (9%):
            ₹<?= shopBillInvoiceMoney($bill['cgst']) ?>
        </p>

        <p>
            SGST (9%):
            ₹<?= shopBillInvoiceMoney($bill['sgst']) ?>
        </p>

        <h3>
            Grand Total:
            ₹<?= shopBillInvoiceMoney($bill['grand_total']) ?>
        </h3>

    </section>

    <!-- These controls are hidden in print mode. -->
    <div class="no-print">
        <button
            type="button"
            onclick="window.print()"
        >
            Print / Save as PDF
        </button>

        <a href="billing.php">
            Back to Billing
        </a>
    </div>

</main>
</body>
</html>
