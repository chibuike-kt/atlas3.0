<?php

namespace App\Http\Requests\Disputes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class OpenDisputeRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'execution_id' => ['required', 'uuid', 'exists:rule_executions,id'],
      'reason'       => ['required', Rule::in([
        'not_authorised',
        'wrong_amount',
        'wrong_recipient',
        'duplicate',
        'service_not_received',
        'technical_error',
        'other',
      ])],
      'description'  => ['required', 'string', 'min:20', 'max:1000'],
    ];
  }

  public function messages(): array
  {
    return [
      'execution_id.required' => 'Select the execution you want to dispute.',
      'execution_id.exists'   => 'That execution does not exist.',
      'reason.required'       => 'A dispute reason is required.',
      'reason.in'             => 'Please select a valid dispute reason.',
      'description.required'  => 'Please describe what went wrong.',
      'description.min'       => 'Description must be at least 20 characters.',
    ];
  }

  protected function failedValidation(Validator $validator): void
  {
    throw new HttpResponseException(response()->json([
      'success' => false,
      'message' => 'Validation failed.',
      'data'    => $validator->errors(),
    ], 422));
  }
}
