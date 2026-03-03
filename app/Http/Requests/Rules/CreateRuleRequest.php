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
    $userId         = $this->user()->id;
    $triggerValues  = array_column(TriggerType::cases(), 'value');
    $actionValues   = array_column(ActionType::cases(), 'value');
    $amountValues   = array_column(AmountType::cases(), 'value');

    return [
      'name'             => ['required', 'string', 'min:3', 'max:120'],
      'rule_text'        => ['sometimes', 'nullable', 'string', 'max:500'],

      // Scoped to the authenticated user's accounts
      'connected_account_id' => [
        'required',
        'uuid',
        Rule::exists('connected_accounts', 'id')->where('user_id', $userId)->where('is_active', 1),
      ],

      'trigger_type'             => ['required', Rule::in($triggerValues)],
      'trigger_config'           => ['required', 'array'],
      'trigger_config.frequency' => ['sometimes', 'nullable', 'string'],
      'trigger_config.time'      => ['sometimes', 'nullable', 'date_format:H:i'],
      'trigger_config.day'       => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],

      'total_amount_type' => ['required', Rule::in($amountValues)],
      'total_amount'      => ['nullable', 'integer', 'min:100'],

      'actions'               => ['required', 'array', 'min:1', 'max:15'],
      'actions.*.action_type' => ['required', Rule::in($actionValues)],
      'actions.*.amount_type' => ['required', Rule::in($amountValues)],
      'actions.*.amount'      => ['required', 'numeric', 'min:0'],
      'actions.*.label'       => ['sometimes', 'nullable', 'string', 'max:80'],
      'actions.*.config'      => ['sometimes', 'nullable', 'array'],
      'actions.*.step_order'  => ['sometimes', 'nullable', 'integer', 'min:1'],
    ];
  }

  public function messages(): array
  {
    return [
      'name.required'                  => 'Rule name is required.',
      'connected_account_id.required'  => 'Select an account to debit from.',
      'connected_account_id.exists'    => 'Account not found or does not belong to your profile.',
      'trigger_type.required'          => 'A trigger type is required.',
      'trigger_type.in'                => 'Invalid trigger type.',
      'actions.required'               => 'At least one action is required.',
      'actions.min'                    => 'At least one action is required.',
      'actions.max'                    => 'A rule cannot have more than 15 steps.',
      'actions.*.action_type.required' => 'Each action must have a type.',
      'actions.*.action_type.in'       => 'Invalid action type. Valid: send_bank, save_piggyvest, save_cowrywise, convert_crypto, pay_bill.',
      'actions.*.amount_type.in'       => 'Invalid amount type. Valid: fixed, percentage, remainder.',
      'actions.*.amount.required'      => 'Each action must have an amount.',
      'actions.*.amount.min'           => 'Amount must be zero or greater.',
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
