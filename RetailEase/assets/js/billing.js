
(function initializeBilling() {

    // Run this code only on the billing page.
    const productSelect = document.getElementById("productSelect");

    if (!productSelect) {
        return;
    }

    // Retrieve the HTML elements used by the billing page.
    const quantityInput = document.getElementById("quantity");
    const addButton = document.getElementById("addProduct");
    const cartBody = document.getElementById("billingCart");
    const saveButton = document.getElementById("saveBill");
    const message = document.getElementById("billingMessage");

    const subtotalOutput = document.getElementById("billingSubtotal");
    const cgstOutput = document.getElementById("billingCGST");
    const sgstOutput = document.getElementById("billingSGST");
    const grandTotalOutput = document.getElementById("billingGrandTotal");

    // Store cart items using their product IDs.
    const cart = new Map();

  
    function money(amount) {
        return Number(amount).toFixed(2);
    }


    function showMessage(text, isError = false) {
        message.textContent = text;
        message.style.color = isError ? "#b00020" : "#16733b";
    }

    
     //Calculate and display estimated totals.
     
     //The PHP API recalculates these totals before saving the bill.
     
    function updateTotals() {

        let subtotalInPaise = 0;

        // Add the subtotal of every product in the cart.
        cart.forEach(function (item) {
            const unitPriceInPaise = Math.round(item.price * 100);

            subtotalInPaise +=
                unitPriceInPaise * item.quantity;
        });

        // Calculate CGST and SGST at 9% each.
        const cgstInPaise =
            Math.round(subtotalInPaise * 0.09);

        const sgstInPaise =
            Math.round(subtotalInPaise * 0.09);

        // Calculate the grand total.
        const grandTotalInPaise =
            subtotalInPaise + cgstInPaise + sgstInPaise;

        // Update the values displayed on the page.
        subtotalOutput.textContent =
            money(subtotalInPaise / 100);

        cgstOutput.textContent =
            money(cgstInPaise / 100);

        sgstOutput.textContent =
            money(sgstInPaise / 100);

        grandTotalOutput.textContent =
            money(grandTotalInPaise / 100);
    }

    /**
     * Display the current cart in the table.
     */
    function renderCart() {

        // Remove the old rows before creating updated rows.
        cartBody.replaceChildren();

        cart.forEach(function (item, productId) {

            const row = document.createElement("tr");

            // Create the product name cell.
            const nameCell = document.createElement("td");
            nameCell.textContent = item.name;

            // Create the unit price cell.
            const priceCell = document.createElement("td");
            priceCell.textContent = "₹" + money(item.price);

            // Create the quantity cell.
            const quantityCell = document.createElement("td");
            quantityCell.textContent = String(item.quantity);

            // Create the line subtotal cell.
            const subtotalCell = document.createElement("td");
            subtotalCell.textContent =
                "₹" + money(item.price * item.quantity);

            // Create the remove button.
            const actionCell = document.createElement("td");
            const removeButton = document.createElement("button");

            removeButton.type = "button";
            removeButton.textContent = "Remove";

            // Remove the product and refresh the cart.
            removeButton.addEventListener("click", function () {

                cart.delete(productId);

                renderCart();
                updateTotals();

                showMessage("Product removed from the cart.");
            });

            actionCell.appendChild(removeButton);

            // Add all cells to the row.
            row.append(
                nameCell,
                priceCell,
                quantityCell,
                subtotalCell,
                actionCell
            );

            cartBody.appendChild(row);
        });

        updateTotals();
    }

    
     // Add the selected product to the cart.

    addButton.addEventListener("click", function () {

        const option = productSelect.selectedOptions[0];

        // Ensure a product has been selected.
        if (!option || !option.value) {
            showMessage("Please select a product.", true);
            return;
        }

        // Read product information from the selected option.
        const productId = option.value;
        const productName = option.dataset.name;
        const price = Number(option.dataset.price);
        const availableStock = Number(option.dataset.stock);
        const quantity = Number(quantityInput.value);

        // Quantity must be a positive whole number.
        if (!Number.isInteger(quantity) || quantity < 1) {
            showMessage(
                "Enter a positive whole-number quantity.",
                true
            );
            return;
        }

        // Validate the displayed price and stock values.
        if (
            !Number.isFinite(price) ||
            price < 0 ||
            !Number.isInteger(availableStock) ||
            availableStock < 0
        ) {
            showMessage("Invalid product information.", true);
            return;
        }

        // Check whether the product is already in the cart.
        const existingItem = cart.get(productId);

        const previousQuantity = existingItem
            ? existingItem.quantity
            : 0;

        // Prevent the requested quantity from exceeding stock.
        if (previousQuantity + quantity > availableStock) {
            showMessage(
                "Requested quantity exceeds available stock.",
                true
            );
            return;
        }

        if (existingItem) {

            // Increase the quantity of an existing cart item.
            existingItem.quantity += quantity;

        } else {

            // Create a new cart item.
            cart.set(productId, {
                product_id: Number(productId),
                name: productName,
                price: price,
                quantity: quantity
            });
        }

        // Refresh the cart and clear the quantity field.
        renderCart();

        quantityInput.value = "1";

        showMessage("Product added successfully.");
    });

    /**
     * Send the cart to the PHP API and create the bill.
     */
    saveButton.addEventListener("click", async function () {

        // A bill must contain at least one product.
        if (cart.size === 0) {
            showMessage(
                "Add at least one product before creating a bill.",
                true
            );
            return;
        }

        // Send only product IDs and quantities.
        // The server retrieves prices and recalculates totals.
        const items = Array.from(cart.values()).map(function (item) {
            return {
                product_id: item.product_id,
                quantity: item.quantity
            };
        });

        // Disable the button while the request is in progress.
        saveButton.disabled = true;

        showMessage("Creating bill...");

        try {

            // Send the cart to the PHP billing API.
            const response = await fetch(
                "includes/billing_api.php",
                {
                    method: "POST",

                    headers: {
                        "Content-Type": "application/json"
                    },

                    body: JSON.stringify({
                        items: items
                    })
                }
            );

            // Read the JSON response from the server.
            const result = await response.json();

            // Display an error if the bill could not be saved.
            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Unable to create bill."
                );
            }

            // Redirect to the newly created invoice.
            window.location.href =
                "invoice.php?id=" +
                encodeURIComponent(result.bill_id);

        } catch (error) {

            // Allow the user to retry if an error occurs.
            showMessage(
                error.message || "A network error occurred.",
                true
            );

            saveButton.disabled = false;
        }
    });

    // Display the initial empty cart.
    renderCart();

})();
