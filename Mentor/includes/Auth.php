<?php
class Auth {

    protected $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Login user
     * @param string $email
     * @param string $password
     * @return array
     */
    public function login($email, $password) {
        try {
            // Case-insensitive email match
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Use the correct password column
                if (password_verify($password, $user['password_hash'])) {
                    return [
                        'success' => true,
                        'user' => [
                            'user_id' => $user['id'], // your table uses 'id'
                            'role' => $user['role'],
                            'email' => $user['email'],
                            'first_name' => $user['name'], // your table uses 'name'
                        ]
                    ];
                } else {
                    return ['success' => false, 'message' => 'Incorrect password.'];
                }
            } else {
                return ['success' => false, 'message' => 'Email not found.'];
            }

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Login error: ' . $e->getMessage()];
        }
    }
}