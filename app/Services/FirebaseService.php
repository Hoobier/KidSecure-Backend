<?php
//FirebaseService.php
namespace App\Services;

use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\EmailExists;

class FirebaseService
{
    public function __construct(protected FirebaseAuth $auth) {}

    public function getAuth(): FirebaseAuth
    {
        return $this->auth;
    }

    public function createParentAccount(string $email, string $fullName): array
    {
        $tempPassword = $this->generateTempPassword();

        try {
            $userRecord = $this->auth->createUser([
                'email' => $email,
                'emailVerified' => false,
                'password' => $tempPassword,
                'displayName' => $fullName,
            ]);
        } catch (EmailExists) {
            // Parent already has a Firebase account (e.g. re-enrollment edge case)
            $existing = $this->auth->getUserByEmail($email);
            return ['uid' => $existing->uid, 'password' => null, 'reused' => true];
        }

        return ['uid' => $userRecord->uid, 'password' => $tempPassword, 'reused' => false];
    }

    protected function generateTempPassword(): string
    {
        return substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0, 10);
    }

    public function resetParentPassword(string $email): array
    {
            $tempPassword = $this->generateTempPassword();

            $existing = $this->auth->getUserByEmail($email);

            $this->auth->changeUserPassword($existing->uid, $tempPassword);

            return ['uid' => $existing->uid, 'password' => $tempPassword];
    }
}