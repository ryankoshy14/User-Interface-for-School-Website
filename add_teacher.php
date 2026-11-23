<?php


// This code here is PHP   

// Make database errors throw exceptions, so try/catch can handle them.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 1. Database settings (check the port on the MAMP start page)
$db_host = "127.0.0.1";
$db_user = "root";
$db_pass = "root";
$db_name = "MELBOURNE_HIGH_SCHOOL";
$db_port = 8889;   // 8889 on Mac, 3306 on Windows

// Makes user text safe to display in HTML.
function h($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

// 2. Connect to the database
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Could not connect to the database: " . h($e->getMessage()));
}

// 3. Load the departments for the dropdown
// (department_id is a required foreign key, so the user must pick an existing one)
$departments = [];
$result = $conn->query("SELECT department_id, department_name FROM DEPARTMENT ORDER BY department_name");
while ($row = $result->fetch_assoc()) {
    $departments[$row['department_id']] = $row['department_name'];
}

$employment_options = ["Full-time", "Part-time", "Casual"];

// 4. Variables used by the page
$errors  = [];   // one message per validation problem
$success = "";   // success message after a successful insert

// What the user typed. Used to refill the form after an error.
$values = [
    'first_name'        => '',
    'last_name'         => '',
    'email'             => '',
    'mobile'            => '',
    'subject_expertise' => '',
    'employment_status' => '',
    'date_employed'     => '',
    'department_id'     => '',
];

// 5. Runs only when the "Add" button has been pressed
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Read the submitted values and trim extra spaces
    foreach ($values as $field => $unused) {
        $values[$field] = trim($_POST[$field] ?? '');
    }

    // --- Validation: each check matches a rule in the TEACHER table ---

    // first_name: required, max 50, letters only
    if ($values['first_name'] === '') {
        $errors[] = "First name is required.";
    } elseif (mb_strlen($values['first_name']) > 50) {
        $errors[] = "First name must be 50 characters or fewer.";
    } elseif (!preg_match("/^[\p{L}][\p{L} '\-\.]*$/u", $values['first_name'])) {
        $errors[] = "First name can only contain letters, spaces, hyphens, apostrophes and full stops.";
    }

    // last_name: same rules as first name
    if ($values['last_name'] === '') {
        $errors[] = "Last name is required.";
    } elseif (mb_strlen($values['last_name']) > 50) {
        $errors[] = "Last name must be 50 characters or fewer.";
    } elseif (!preg_match("/^[\p{L}][\p{L} '\-\.]*$/u", $values['last_name'])) {
        $errors[] = "Last name can only contain letters, spaces, hyphens, apostrophes and full stops.";
    }

    // email: optional, but if given it must be valid, max 100, and not already used (UNIQUE)
    if ($values['email'] !== '') {
        if (mb_strlen($values['email']) > 100) {
            $errors[] = "Email must be 100 characters or fewer.";
        } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email is not a valid email address (for example: name@school.edu).";
        } else {
            // Ask the database whether this email is already taken
            $check = $conn->prepare("SELECT teacher_id FROM TEACHER WHERE email = ?");
            $check->bind_param("s", $values['email']);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $errors[] = "A teacher with this email already exists. Email addresses must be unique.";
            }
            $check->close();
        }
    }

    // mobile: optional, 8 to 20 digits (spaces and a leading + allowed)
    if ($values['mobile'] !== '') {
        if (!preg_match("/^\+?[0-9 ]{8,20}$/", $values['mobile'])) {
            $errors[] = "Mobile must be 8 to 20 digits (spaces and a leading + are allowed).";
        }
    }

    // subject_expertise: optional, max 100 characters
    if (mb_strlen($values['subject_expertise']) > 100) {
        $errors[] = "Subject expertise must be 100 characters or fewer.";
    }

    // employment_status: optional, but must be one of the listed options
    if ($values['employment_status'] !== '' && !in_array($values['employment_status'], $employment_options, true)) {
        $errors[] = "Employment status must be one of the listed options.";
    }

    // date_employed: optional, but must be a real date (YYYY-MM-DD) and not in the future
    if ($values['date_employed'] !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $values['date_employed']);
        if (!$date || $date->format('Y-m-d') !== $values['date_employed']) {
            $errors[] = "Date employed must be a real date in the format YYYY-MM-DD.";
        } elseif ($date > new DateTime('today')) {
            $errors[] = "Date employed cannot be in the future.";
        }
    }

    // department_id: required, and must be a department that exists (foreign key)
    if ($values['department_id'] === '') {
        $errors[] = "Department is required.";
    } elseif (!ctype_digit($values['department_id']) || !isset($departments[(int)$values['department_id']])) {
        $errors[] = "The selected department does not exist.";
    }

    // --- Insert the record, but only if there were no validation errors ---
    if (empty($errors)) {

        // Optional fields left blank are saved as NULL, not as an empty string
        // (two empty emails would break the UNIQUE rule).
        $first_name = $values['first_name'];
        $last_name  = $values['last_name'];
        $email      = $values['email'] !== '' ? $values['email'] : null;
        $mobile     = $values['mobile'] !== '' ? $values['mobile'] : null;
        $subject    = $values['subject_expertise'] !== '' ? $values['subject_expertise'] : null;
        $status     = $values['employment_status'] !== '' ? $values['employment_status'] : null;
        $employed   = $values['date_employed'] !== '' ? $values['date_employed'] : null;
        $dept_id    = (int)$values['department_id'];

        try {
            // Prepared statement: the ? placeholders keep user input separate
            // from the SQL, which prevents SQL injection.
            $stmt = $conn->prepare(
                "INSERT INTO TEACHER
                    (first_name, last_name, email, mobile, subject_expertise, employment_status, date_employed, department_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            // 7 strings (s) and 1 integer (i), in the same order as the placeholders
            $stmt->bind_param("sssssssi", $first_name, $last_name, $email, $mobile, $subject, $status, $employed, $dept_id);
            $stmt->execute();

            // Success message (insert_id is the new teacher_id)
            $success = "Success: " . $first_name . " " . $last_name . " was added to the TEACHER table (teacher_id "
                     . $conn->insert_id . ").";
            $stmt->close();

            // Clear the form for the next entry
            foreach ($values as $field => $unused) {
                $values[$field] = '';
            }

        } catch (mysqli_sql_exception $e) {
            // The database itself rejected the record
            if ($e->getCode() == 1062) {
                $errors[] = "That email address is already in use. No record was added.";
            } elseif ($e->getCode() == 1452) {
                $errors[] = "The selected department is not valid. No record was added.";
            } else {
                $errors[] = "The database rejected the record: " . h($e->getMessage());
            }
        }
    }
}

// 6. Loads the teachers to show under the form (runs after any insert, so new rows appear)
$teachers = $conn->query(
    "SELECT t.teacher_id, t.first_name, t.last_name, t.email, t.mobile,
            t.subject_expertise, t.employment_status, t.date_employed, d.department_name
     FROM TEACHER t
     INNER JOIN DEPARTMENT d ON t.department_id = d.department_id
     ORDER BY t.teacher_id"
);

//  The PHP ends
// The HTML section starts below.
?>
<!DOCTYPE html>
<html lang="en">
<!-- This is where the HTML code starts -->
<head>
<meta charset="UTF-8">
<title>Add a teacher - Melbourne High School</title>
</head>
<body>

<h1>Add a teacher</h1>
<p>Fields marked * are required. Everything else can be left blank.</p>

<!-- Error messages (only shown if there are errors) -->
<?php if (!empty($errors)): ?>
    <div role="alert">
        <p><strong>The teacher was not added. Please fix the following:</strong></p>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Success message (only shown after a successful insert) -->
<?php if ($success !== ""): ?>
    <div role="status">
        <p><strong><?= h($success) ?></strong></p>
    </div>
<?php endif; ?>

<!-- The form. "novalidate" turns off the browser's own checks so the PHP validation runs instead. -->
<form method="post" action="" novalidate>

    <p>
        <label for="first_name">First name *</label><br>
        <input type="text" id="first_name" name="first_name" maxlength="100" value="<?= h($values['first_name']) ?>">
    </p>

    <p>
        <label for="last_name">Last name *</label><br>
        <input type="text" id="last_name" name="last_name" maxlength="100" value="<?= h($values['last_name']) ?>">
    </p>

    <p>
        <label for="email">Email (must be unique)</label><br>
        <input type="text" id="email" name="email" maxlength="150" value="<?= h($values['email']) ?>">
    </p>

    <p>
        <label for="mobile">Mobile</label><br>
        <input type="text" id="mobile" name="mobile" maxlength="30" value="<?= h($values['mobile']) ?>">
    </p>

    <p>
        <label for="subject_expertise">Subject expertise</label><br>
        <input type="text" id="subject_expertise" name="subject_expertise" maxlength="150" value="<?= h($values['subject_expertise']) ?>">
    </p>

    <p>
        <label for="date_employed">Date employed</label><br>
        <input type="date" id="date_employed" name="date_employed" value="<?= h($values['date_employed']) ?>">
    </p>

    <!-- Dropdown built from the DEPARTMENT table. The value sent is the department_id. -->
    <p>
        <label for="department_id">Department *</label><br>
        <select id="department_id" name="department_id">
            <option value="">Select a department</option>
            <?php foreach ($departments as $id => $name): ?>
                <option value="<?= h($id) ?>" <?= ((string)$id === $values['department_id']) ? 'selected' : '' ?>>
                    <?= h($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <!-- Radio buttons for employment status -->
    <p>
        Employment status<br>
        <?php foreach ($employment_options as $option): ?>
            <label>
                <input type="radio" name="employment_status" value="<?= h($option) ?>"
                    <?= ($values['employment_status'] === $option) ? 'checked' : '' ?>>
                <?= h($option) ?>
            </label>
        <?php endforeach; ?>
    </p>

    <p>
        <button type="submit">Add</button>
    </p>

</form>

<hr>

<!-- Table of the current teachers -->
<h2>Current records in the TEACHER table</h2>
<table border="1">
    <thead>
        <tr>
            <th>ID</th>
            <th>First name</th>
            <th>Last name</th>
            <th>Email</th>
            <th>Mobile</th>
            <th>Subject</th>
            <th>Status</th>
            <th>Employed</th>
            <th>Department</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($t = $teachers->fetch_assoc()): ?>
            <tr>
                <td><?= h($t['teacher_id']) ?></td>
                <td><?= h($t['first_name']) ?></td>
                <td><?= h($t['last_name']) ?></td>
                <td><?= h($t['email']) ?></td>
                <td><?= h($t['mobile']) ?></td>
                <td><?= h($t['subject_expertise']) ?></td>
                <td><?= h($t['employment_status']) ?></td>
                <td><?= h($t['date_employed']) ?></td>
                <td><?= h($t['department_name']) ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>

</body>
</html>
<!-- The HTML part has ended  -->
<?php
// PHP: close the database connection now that the page is finished
$conn->close();
?>
