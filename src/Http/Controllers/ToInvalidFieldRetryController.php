<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToDelivery;
use Illuminate\Http\RedirectResponse;
use Statamic\Http\Controllers\CP\CpController;

/** Native utility permission/session/CSRF action; never a Bridge wire endpoint. */
class ToInvalidFieldRetryController extends CpController
{
    public function __invoke(ToDelivery $delivery): RedirectResponse
    {
        $this->authorize('access discussionbridge utility');
        $user = $this->request->user();
        $id = filter_var($this->request->input('delivery_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $identity = $this->request->input('request_identity');
        if (! $user || $id === false || ! is_string($identity)) {
            return back()->with('error', 'The retained delivery needs current native reconciliation.');
        }
        try {
            $result = $delivery->retryInvalidField($id, $identity, (string) $user->getAuthIdentifier());
            if ($result['state'] === 'queued') return back()->with('success', 'The retained delivery retry is queued.');
            if ($result['state'] === 'reconciliation_required') return back()->with('error', 'This retry was already attempted and needs reconciliation. No additional retry was queued.');
            return back()->with('success', 'This retry action was already recorded. No additional retry was queued.');
        } catch (CmsException) {
            return back()->with('error', 'The retained delivery needs current native reconciliation. No new retry was authorized.');
        }
    }
}
