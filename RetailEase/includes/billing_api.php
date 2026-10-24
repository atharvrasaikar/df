
<?php

header('Content-Type: application/json; charset=utf-8');

// Reuse the existing database connection.
require_once __DIR__ . '/db.php';

/**
 * Send a JSON response and stop execution.
 */
function billingApiReply(array $data, int $status = 200): void
{
    http_response_code($status);

    echo json_encode($data);

    exit;
}

// This API accepts POST requests only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    billingApiReply([
        'success' => false,
        'message' => 'POST request required.'
    ], 405);
}

// Read the JSON data sent by the billing page.
$requestBody = file_get_contents('php://input');
$requestData = json_decode($requestBody, true);

// Check that the cart exists and contains products.
if (
    !is_array($requestData) ||
    !isset($requestData['items']) ||
    !is_array($requestData['items']) ||
    count($requestData['items']) === 0
) {
    billingApiReply([
        'success' => false,
        'message' => 'The cart is empty or invalid.'
    ], 400);
}

// Validate product IDs and quantities.
// Combine duplicate entries for the same product.
$quantities = [];

foreach ($requestData['items'] as $item) {

    if (
        !is_array($item) ||
        !isset($item['product_id'], $item['quantity'])
    ) {
        billingApiReply([
            'success' => false,
            'message' => 'Invalid cart item.'
        ], 400);
    }

    $productId = filter_var(
        $item['product_id'],
        FILTER_VALIDATE_INT
    );

    $quantity = filter_var(
        $item['quantity'],
        FILTER_VALIDATE_INT
    );

    // Both values must be positive integers.
    if (
        $productId === false ||
        $productId < 1 ||
        $quantity === false ||
        $quantity < 1
    ) {
        billingApiReply([
            'success' => false,
            'message' => 'Product ID and quantity must be positive integers.'
        ], 400);
    }

    // Add quantities if the product appears more than once.
    $quantities[$productId] =
        ($quantities[$productId] ?? 0) + $quantity;

    if ($quantities[$productId] > 100000) {
        billingApiReply([
            'success' => false,
            'message' => 'Requested quantity is too large.'
        ], 400);
    }
}

try {


    $pdo->beginTransaction();

    // Retrieve product details from the database.
    $productQuery = $pdo->prepare(
        "SELECT id, name, price, stock
         FROM products
         WHERE id = ?"
    );

    $itemsToSave = [];

    // Calculate subtotal in paise to reduce rounding errors.
    $subtotalInPaise = 0;

    foreach ($quantities as $productId => $quantity) {

        $productQuery->execute([$productId]);

        $product = $productQuery->fetch(PDO::FETCH_ASSOC);

        // Reject product IDs that do not exist.
        if (!$product) {
            throw new DomainException(
                'A selected product does not exist.'
            );
        }

        // Check the latest available stock.
        if ((int) $product['stock'] < $quantity) {
            throw new DomainException(
                'Insufficient stock for ' . $product['name'] . '.'
            );
        }

        // Get the actual price from the database.
        $price = (float) $product['price'];

        if (!is_finite($price) || $price < 0) {
            throw new DomainException(
                'Invalid price for ' . $product['name'] . '.'
            );
        }

        // Convert the price from rupees to paise.
        $unitPriceInPaise = (int) round($price * 100);

        // Calculate the subtotal for this product.
        $itemSubtotalInPaise =
            $unitPriceInPaise * $quantity;

        $subtotalInPaise += $itemSubtotalInPaise;

        // Keep the validated values for database insertion.
        $itemsToSave[] = [
            'product_id' => (int) $product['id'],
            'quantity' => $quantity,
            'unit_price' => $unitPriceInPaise,
            'subtotal' => $itemSubtotalInPaise
        ];
    }

    // Calculate 9% CGST and 9% SGST.
    $cgstInPaise = (int) round($subtotalInPaise * 0.09);
    $sgstInPaise = (int) round($subtotalInPaise * 0.09);

    // Calculate the final bill amount.
    $grandTotalInPaise =
        $subtotalInPaise + $cgstInPaise + $sgstInPaise;

    // Convert all totals back to rupees for storage.
    $subtotal = $subtotalInPaise / 100;
    $cgst = $cgstInPaise / 100;
    $sgst = $sgstInPaise / 100;
    $grandTotal = $grandTotalInPaise / 100;

    // Insert the bill summary.
    $insertBill = $pdo->prepare(
        "INSERT INTO bills
            (total, cgst, sgst, grand_total, created_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );

    $insertBill->execute([
        $subtotal,
        $cgst,
        $sgst,
        $grandTotal
    ]);

    // Obtain the ID of the newly created bill.
    $billId = (int) $pdo->lastInsertId();

    // Prepare the statement for saving individual bill items.
    $insertItem = $pdo->prepare(
        "INSERT INTO bill_items
            (bill_id, product_id, quantity, unit_price, subtotal)
         VALUES (?, ?, ?, ?, ?)"
    );

    // Reduce stock only when enough stock remains.
    $updateStock = $pdo->prepare(
        "UPDATE products
         SET stock = stock - ?
         WHERE id = ? AND stock >= ?"
    );

    foreach ($itemsToSave as $item) {

        // Save the product details against this bill.
        $insertItem->execute([
            $billId,
            $item['product_id'],
            $item['quantity'],
            $item['unit_price'] / 100,
            $item['subtotal'] / 100
        ]);

        // Deduct the quantity sold from inventory.
        $updateStock->execute([
            $item['quantity'],
            $item['product_id'],
            $item['quantity']
        ]);

        // If stock could not be deducted, cancel the bill.
        if ($updateStock->rowCount() !== 1) {
            throw new DomainException(
                'Stock changed during billing. Please try again.'
            );
        }
    }

    // Save all changes permanently.
    $pdo->commit();

    // Send the bill ID back to JavaScript.
    billingApiReply([
        'success' => true,
        'bill_id' => $billId,
        'message' => 'Bill created successfully.'
    ]);

} catch (Throwable $error) {

    // Undo database changes if anything failed.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Record technical details in the server log.
    error_log(
        'ShopBill billing API error: ' . $error->getMessage()
    );

    // Return a suitable message to the frontend.
    $isValidationError =
        $error instanceof DomainException;

    billingApiReply([
        'success' => false,
        'message' => $isValidationError
            ? $error->getMessage()
            : 'Unable to create the bill. Please try again.'
    ], $isValidationError ? 400 : 500);
}
