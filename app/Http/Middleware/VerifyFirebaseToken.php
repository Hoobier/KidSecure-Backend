<?php
// app/Http/Middleware/VerifyFirebaseToken.php
namespace App\Http\Middleware;
 
use App\Models\ParentAccount;
use Closure;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Symfony\Component\HttpFoundation\Response;
 
/**
 * Verifies a Firebase ID token sent by the Flutter parent app and
 * resolves it to the matching ParentAccount, attaching it to the
 * request so controllers can read it without looking it up again.
 *
 * This is a completely separate auth path from the admin portal's
 * Sanctum cookie sessions — it does not touch or interfere with
 * StudentController/ParentController's existing auth:sanctum routes.
 *
 * Flutter side sends: Authorization: Bearer <firebase-id-token>
 * (NOT a Sanctum token — this is the token from FirebaseAuth.instance
 * .currentUser.getIdToken() after a normal Firebase Auth login.)
 */
class VerifyFirebaseToken
{
    public function __construct(protected FirebaseAuth $auth)
    {
    }
 
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
 
        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json([
                'message' => 'You need to be logged in to do that.',
            ], 401);
        }
 
        $idToken = substr($header, 7);
 
        try {
            $verifiedToken = $this->auth->verifyIdToken($idToken);
        } catch (FailedToVerifyToken $e) {
            return response()->json([
                'message' => 'Your session has expired. Please log in again.',
            ], 401);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to verify your login. Please try again.',
            ], 401);
        }
 
        $firebaseUid = $verifiedToken->claims()->get('sub');
 
        $parent = ParentAccount::where('firebaseUid', $firebaseUid)->first();
 
        if (!$parent) {
            return response()->json([
                'message' => 'No parent account was found for this login.',
            ], 404);
        }
 
        // Available in controllers via: $request->attributes->get('parentAccount')
        $request->attributes->set('parentAccount', $parent);
 
        return $next($request);
    }
}
 