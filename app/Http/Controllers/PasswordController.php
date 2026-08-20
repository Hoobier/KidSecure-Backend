<?php
// app/Http/Controllers/PasswordController.php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Firebase\Auth\ActionCodeSettings\ValidatedActionCodeSettings;

class PasswordController extends Controller
{
    /**
     * POST /api/password/forgot
     * Sends a password reset email via Firebase
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please enter a valid email address.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim((string) $request->input('email')));

        try {
            $firebase = app(\App\Services\FirebaseService::class);
            $auth = $firebase->getAuth();

            $actionCodeSettings = ValidatedActionCodeSettings::fromArray([
                'url' => config('services.firebase.reset_continue_url'),
                'handleCodeInApp' => true,
                'androidPackageName' => config('services.firebase.android_package_name'),
                'iOSBundleId' => config('services.firebase.ios_bundle_id'),
            ]);

            \Log::info("Requesting Firebase password reset link for {$email}");
            $auth->sendPasswordResetLink($email, $actionCodeSettings);

            return response()->json([
                'message' => 'Password reset link has been sent to your email.',
            ]);

        } catch (UserNotFound $e) {
            // Keep the response generic so this endpoint cannot enumerate users.
            return response()->json([
                'message' => 'If your email is registered, you will receive a password reset link.',
            ]);

        } catch (\Throwable $e) {
            \Log::error("Password reset failed for {$email}: " . $e->getMessage());
            
            return response()->json([
                'message' => 'Unable to send password reset link. Please try again.',
            ], 500);
        }
    }

    /**
     * POST /api/password/change
     * Changes the password for the authenticated parent
     * Requires: currentPassword, newPassword
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currentPassword' => 'required|string|min:6',
            'newPassword' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check your passwords.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $parent = $request->attributes->get('parentAccount');
        $currentPassword = $request->input('currentPassword');
        $newPassword = $request->input('newPassword');

        try {
            $firebase = app(\App\Services\FirebaseService::class);
            $auth = $firebase->getAuth();

            // Get the Firebase user
            $user = $auth->getUserByEmail($parent->email);

            // First, re-authenticate the user with their current password
            // We need to verify the current password is correct
            try {
                // Use Firebase Auth REST API to verify password
                $this->verifyPassword($parent->email, $currentPassword);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Current password is incorrect.',
                ], 401);
            }

            // Update the password in Firebase
            $auth->changeUserPassword($user->uid, $newPassword);

            // Log the password change
            \Log::info("Password changed for parent: {$parent->email}");

            return response()->json([
                'message' => 'Password changed successfully.',
            ]);

        } catch (UserNotFound $e) {
            return response()->json([
                'message' => 'Account not found. Please contact support.',
            ], 404);

        } catch (\Throwable $e) {
            \Log::error("Password change failed for {$parent->email}: " . $e->getMessage());
            
            return response()->json([
                'message' => 'Unable to change password. Please try again.',
            ], 500);
        }
    }

    /**
     * Verify a user's password using Firebase REST API
     */
    private function verifyPassword(string $email, string $password): void
    {
        $apiKey = config('services.firebase.api_key');
        
        $client = new \GuzzleHttp\Client();
        $response = $client->post(
            "https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key={$apiKey}",
            [
                'json' => [
                    'email' => $email,
                    'password' => $password,
                    'returnSecureToken' => true,
                ],
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Invalid credentials');
        }
    }
}