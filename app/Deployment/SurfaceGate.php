<?php
namespace App\Deployment;

/** Applied before framework boot, so redirects, sessions and assets cannot leak across hosts. */
final class SurfaceGate
{
    public static function allows(string $surface, string $path): bool
    {
        if (!in_array($surface,['api','web'],true)) return false;
        // Reject alternate encodings, traversal and front-controller aliases at the API boundary.
        if (preg_match('/[%\\\\\x00-\x20]/',$path) || str_contains($path,'//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$path)) return false;
        $mobile=preg_match('~(?:^|/)mobile/v1(?:/|$)~',$path)===1;
        return $surface==='api' ? str_starts_with($path,'/mobile/v1/') : !$mobile;
    }
}
