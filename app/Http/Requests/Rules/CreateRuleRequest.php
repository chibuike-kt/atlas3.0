<?php

namespace App\Http\Requests\Rules;

use App\Enums\ActionType;
use App\Enums\AmountType;
use App\Enums\TriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateRuleRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'name'                     => ['required', 'string', 'min:3', 'max:120'],
      'rule_text'                => ['sometimes', 'string', 'max:500'],
      'connected_account_id'     => ['required', 'uuid', 'exists:connected_accounts,id'],
      'trigger_type'             => ['required', Rule::enum(TriggerType::class)],
      'trigger_config'           => ['required', 'array'],
      'trigger_config.frequency' => ['sometimes', 'string'],
      'trigger_config.time'      => ['sometimes', 'date_format:H:i'],
      'trigger_config.day'       => ['sometimes', 'integer', 'min:1', 'max:31'],
      'total_amount_type'        => ['required', Rule::enum(AmountType::class)],
      'total_amount'             => ['nullable', 'integer', 'min:100'],
      'actions'                  => ['required', 'array', 'min:1', 'max:15'],
      'actions.*.action_type'    => ['required', Rule::enum(ActionType::class)],
      'actions.*.amount_type'    => ['required', Rule::enum(AmountType::class)],
      'actions.*.amount'         => ['required', 'numeric', 'min:0.01'],
      'actions.*.label'          => ['sometimes', 'string', 'max:80'],
      'actions.*.config'         => ['sometimes', 'array'],
      'actions.*.step_order'     => ['sometimes', 'integer', 'min:1'],
    ];
  }

  public function messages(): array
  {
    return [
      'name.required'                  => 'Rule name is required.',
      'connected_account_id.required'  => 'Select an account to debit from.',
      'connected_account_id.exists'    => 'The selected account does not exist.',
      'trigger_type.required'          => 'A trigger type is required.',
      'actions.required'               => 'At least one action is required.',
      'actions.min'                    => 'At least one action is required.',
      'actions.max'                    => 'A rule cannot have more than 15 steps.',
      'actions.*.action_type.required' => 'Each action must have a type.',
      'actions.*.amount.required'      => 'Each action must have an amount.',
      'actions.*.amount.min'           => 'Amount must be greater than zero.',
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
