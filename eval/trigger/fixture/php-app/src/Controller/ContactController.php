<?php

namespace Acme\Controller;

use PDO;

class ContactController
{
    public function __construct(private PDO $db)
    {
    }

    public function submit(): void
    {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $message = $_POST['message'] ?? '';

        if ($name == '' || $email == '' || $message == '') {
            http_response_code(422);
            echo json_encode(['error' => 'All fields are required']);
            return;
        }

        $this->db->exec("INSERT INTO contact_messages (name, email, message) VALUES ('$name', '$email', '$message')");

        mail('support@example.com', 'New contact message', $message, 'From: ' . $email);

        header('Location: /thanks');
    }

    public function list(): void
    {
        $rows = $this->db->query('SELECT * FROM contact_messages ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            echo '<p>' . $row['name'] . ': ' . $row['message'] . '</p>';
        }
    }
}
