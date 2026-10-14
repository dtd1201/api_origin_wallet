<!doctype html>
<html lang="en">
<body style="margin:0;background:#f3f6f4;color:#18312a;font-family:Arial,sans-serif">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 16px"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden">
<tr><td style="padding:24px 32px;background:#0d4f3c;color:#fff;font-size:22px;font-weight:bold">Origin Wallet</td></tr>
<tr><td style="padding:32px">
@if ($recipientType === 'admin')
<h1 style="margin-top:0;font-size:22px">KYC {{ ucfirst($eventType) }}</h1>
<p><strong>Customer:</strong> {{ $details['display_name'] }}</p>
<p><strong>Account reference:</strong> {{ $details['account_reference'] }}</p>
<p><strong>Verified status:</strong> {{ $details['verified_status'] }}</p>
@if (! empty($details['admin_review_url']))<p><a href="{{ $details['admin_review_url'] }}" style="color:#0d6b50">Review KYC in Origin Wallet</a></p>@endif
@else
@if ($eventType === 'approved')
<h1 style="margin-top:0;font-size:22px">Your KYC is approved</h1>
<p>Your Origin Wallet customer profile is active. You can sign in to view the services currently available to you.</p>
@elseif ($eventType === 'rejected')
<h1 style="margin-top:0;font-size:22px">Your KYC needs attention</h1>
<p>Your verification could not be approved. Please sign in to Origin Wallet to review the available next steps or contact support.</p>
@else
<h1 style="margin-top:0;font-size:22px">More information is required</h1>
<p>Please sign in to Origin Wallet to provide the requested KYC information.</p>
@endif
@endif
<p style="margin-top:28px;color:#5d6d67;font-size:13px">This is an automated Origin Wallet notification.</p>
</td></tr></table></td></tr></table>
</body>
</html>
