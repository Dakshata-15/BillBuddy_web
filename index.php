
<?php
require_once "db.php";

$today = date("Y-m-d");
$error = "";

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Add a new bill
    if (isset($_POST["add_bill"])) {
        $name = trim($_POST["bill_name"] ?? "");
        $category = $_POST["category"] ?? "";
        $amount = $_POST["amount"] ?? "";
        $due_date = $_POST["due_date"] ?? "";

        $allowed = [
            "Electricity",
            "Mobile Recharge",
            "Internet",
            "Rent",
            "Other"
        ];

        $date = DateTime::createFromFormat("!Y-m-d", $due_date);
        $validDate = $date !== false &&
            $date->format("Y-m-d") === $due_date;

        if (
            $name !== "" &&
            strlen($name) <= 100 &&
            in_array($category, $allowed, true) &&
            is_numeric($amount) &&
            (float)$amount >= 0 &&
            (float)$amount <= 99999999.99 &&
            $validDate
        ) {
            $stmt = $conn->prepare(
                "INSERT INTO bills
                (bill_name, category, amount, due_date)
                VALUES (?, ?, ?, ?)"
            );

            if ($stmt) {
                $amountValue = (float)$amount;
                $stmt->bind_param(
                    "ssds",
                    $name,
                    $category,
                    $amountValue,
                    $due_date
                );

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: index.php?message=added");
                    exit;
                }

                $error = "Could not add the bill. Please try again.";
                $stmt->close();
            } else {
                $error = "Could not prepare the bill request.";
            }
        } else {
            $error = "Please enter valid bill details.";
        }
    }

    // Mark a bill as paid
    if (isset($_POST["mark_paid"])) {
        $id = (int)($_POST["bill_id"] ?? 0);

        if ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE bills SET is_paid = 1 WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: index.php?message=paid");
                    exit;
                }

                $error = "Could not update the bill.";
                $stmt->close();
            } else {
                $error = "Could not prepare the update request.";
            }
        } else {
            $error = "Invalid bill ID.";
        }
    }

    // Delete a bill
    if (isset($_POST["delete_bill"])) {
        $id = (int)($_POST["bill_id"] ?? 0);

        if ($id > 0) {
            $stmt = $conn->prepare(
                "DELETE FROM bills WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {
                    $deleted = $stmt->affected_rows;
                    $stmt->close();

                    if ($deleted > 0) {
                        header("Location: index.php?message=deleted");
                    } else {
                        header("Location: index.php?message=notfound");
                    }
                    exit;
                }

                $error = "Could not delete the bill. Please try again.";
                $stmt->close();
            } else {
                $error = "Could not prepare the delete request.";
            }
        } else {
            $error = "Invalid bill ID.";
        }
    }
}

// Fetch bills from the database
$result = $conn->query(
    "SELECT * FROM bills ORDER BY due_date ASC, id DESC"
);

if (!$result) {
    die("Database query failed: " . $conn->error);
}

$bills = $result->fetch_all(MYSQLI_ASSOC);

// Calculate bill statistics
$total = 0;
$upcoming = 0;
$due_today = 0;
$overdue = 0;

foreach ($bills as &$bill) {
    if ((int)$bill["is_paid"] === 1) {
        $bill["status"] = "Paid";
    } elseif ($bill["due_date"] < $today) {
        $bill["status"] = "Overdue";
    } elseif ($bill["due_date"] === $today) {
        $bill["status"] = "Due Today";
    } else {
        $bill["status"] = "Upcoming";
    }

    if ($bill["status"] !== "Paid") {
        $total += (float)$bill["amount"];

        if ($bill["status"] === "Upcoming") {
            $upcoming++;
        }

        if ($bill["status"] === "Due Today") {
            $due_today++;
        }

        if ($bill["status"] === "Overdue") {
            $overdue++;
        }
    }
}

unset($bill);
$conn->close();

// Escape displayed text for HTML
function h($value) {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Format money in Indian rupees
function money($value) {
    return "₹" . number_format((float)$value, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BillBuddy - Bill Reminder</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">

    <header class="header">
        <div>
            <p class="eyebrow">YOUR PERSONAL BILL ORGANISER</p>
            <h1>Bill<span>Buddy.</span></h1>
            <p class="subtitle">
                Every bill remembered. Every payment on time.
            </p>
        </div>

        <div class="today-card">
            <span>Today's date</span>
            <strong><?= h(date("d M Y")) ?></strong>
        </div>
    </header>

    <?php if (isset($_GET["message"])): ?>
        <p class="message">
            <?php
            $messages = [
                "added" => "Bill added successfully!",
                "paid" => "Bill marked as paid!",
                "deleted" => "Bill deleted successfully!",
                "notfound" => "That bill was not found. Refresh and try again."
            ];

            echo h($messages[$_GET["message"]] ?? "");
            ?>
        </p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <section class="stats">
        <article class="stat">
            <span>Total unpaid amount</span>
            <strong><?= h(money($total)) ?></strong>
        </article>

        <article class="stat upcoming">
            <span>Upcoming</span>
            <strong><?= $upcoming ?></strong>
        </article>

        <article class="stat due">
            <span>Due today</span>
            <strong><?= $due_today ?></strong>
        </article>

        <article class="stat overdue">
            <span>Overdue</span>
            <strong><?= $overdue ?></strong>
        </article>
    </section>

    <section class="panel">
        <h2>＋ Add a new bill</h2>

        <form method="POST" action="index.php">
            <div class="form-grid">
                <div>
                    <label for="bill_name">Bill name</label>
                    <input
                        id="bill_name"
                        name="bill_name"
                        maxlength="100"
                        placeholder="e.g. Home electricity"
                        required
                    >
                </div>

                <div>
                    <label for="category">Category</label>
                    <select id="category" name="category" required>
                        <option value="Electricity">Electricity</option>
                        <option value="Mobile Recharge">Mobile Recharge</option>
                        <option value="Internet">Internet</option>
                        <option value="Rent">Rent</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div>
                    <label for="amount">Amount (₹)</label>
                    <input
                        id="amount"
                        name="amount"
                        type="number"
                        min="0"
                        max="99999999.99"
                        step="0.01"
                        placeholder="1000"
                        required
                    >
                </div>

                <div>
                    <label for="due_date">Due date</label>
                    <input
                        id="due_date"
                        name="due_date"
                        type="date"
                        required
                    >
                </div>
            </div>

            <button class="primary" type="submit" name="add_bill">
                Add bill
            </button>
        </form>
    </section>

    <section class="panel">
        <h2>Your bills</h2>

        <?php if (count($bills) === 0): ?>
            <p class="empty">
                No bills yet. Add your first bill above!
            </p>
        <?php else: ?>
            <div class="bill-list">
                <?php foreach ($bills as $bill): ?>
                    <article class="bill-card">
                        <div class="bill-info">
                            <h3><?= h($bill["bill_name"]) ?></h3>
                            <p><?= h($bill["category"]) ?></p>
                            <p>
                                Due:
                                <?= h(date(
                                    "d M Y",
                                    strtotime($bill["due_date"])
                                )) ?>
                            </p>
                        </div>

                        <div class="bill-right">
                            <div class="bill-amount">
                                <?= h(money($bill["amount"])) ?>
                            </div>

                            <span class="badge <?= h(
                                strtolower(str_replace(
                                    " ",
                                    "-",
                                    $bill["status"]
                                ))
                            ) ?>">
                                <?= h($bill["status"]) ?>
                            </span>

                            <div class="actions">
                                <?php if ($bill["status"] !== "Paid"): ?>
                                    <form method="POST" action="index.php">
                                        <input
                                            type="hidden"
                                            name="bill_id"
                                            value="<?= (int)$bill["id"] ?>"
                                        >
                                        <button
                                            class="paid-btn"
                                            type="submit"
                                            name="mark_paid"
                                        >
                                            Mark paid
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form
                                    method="POST"
                                    action="index.php"
                                    onsubmit="return confirm('Delete this bill? This cannot be undone.');"
                                >
                                    <input
                                        type="hidden"
                                        name="bill_id"
                                        value="<?= (int)$bill["id"] ?>"
                                    >
                                    <button
                                        class="delete-btn"
                                        type="submit"
                                        name="delete_bill"
                                    >
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <footer>
        BillBuddy · Never miss a payment again.
    </footer>

</main>
</body>
</html>
