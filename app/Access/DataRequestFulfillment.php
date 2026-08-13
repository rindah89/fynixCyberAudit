<?php

namespace App\Access;

use App\Enums\ResponseStatus;
use App\Mail\EvidenceRequestMail;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class DataRequestFulfillment
{
    public function open(DataRequest $request, ?string $dueAt = null): DataRequestResponse
    {
        return $request->responses()->create([
            'requester_id' => $request->created_by_id,
            'requestee_id' => $request->assigned_to_id,
            'data_request_id' => $request->id,
            'due_at' => $dueAt,
            'status' => ResponseStatus::PENDING,
        ]);
    }

    public function notify(User $assignee): void
    {
        if (! filled($assignee->email)) {
            return;
        }

        Mail::send(new EvidenceRequestMail($assignee->email, $assignee->name ?? ''));
    }

    public function respond(DataRequestResponse $response, ?string $text = null): DataRequestResponse
    {
        if ($text !== null) {
            $response->response = $text;
        }

        $response->status = ResponseStatus::RESPONDED;
        $response->save();

        return $response->refresh();
    }

    public function accept(DataRequestResponse $response): DataRequestResponse
    {
        $response->status = ResponseStatus::ACCEPTED;
        $response->save();

        return $response->refresh();
    }

    public function reject(DataRequestResponse $response): DataRequestResponse
    {
        $response->status = ResponseStatus::REJECTED;
        $response->save();

        return $response->refresh();
    }

    public function reassign(DataRequestResponse $response, User $assignee, bool $notify = true): DataRequestResponse
    {
        $response->requestee_id = $assignee->id;
        $response->status = ResponseStatus::PENDING;
        $response->save();

        $dataRequest = $response->dataRequest;
        if ($dataRequest) {
            $dataRequest->assigned_to_id = $assignee->id;
            $dataRequest->save();
        }

        if ($notify) {
            $this->notify($assignee);
        }

        return $response->refresh();
    }

    public function changeDueDate(DataRequestResponse $response, mixed $dueAt): DataRequestResponse
    {
        $response->due_at = $dueAt;
        $response->save();

        return $response->refresh();
    }
}
