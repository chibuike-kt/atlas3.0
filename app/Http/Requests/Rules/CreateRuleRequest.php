<?php
namespace App\Http\Requests\Rules;

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
        $userId = $this->user()->id;

        return [
            'name'      => ['required', 'string', 'min:3', 'max:120'],
            'rule_text' => ['sometimes', 'nullable', 'string', 'max:500'],

            'connected_account_id' => [
                'required',
                'string',
                Rule::exists('connected_accounts', 'id')
                    ->where('user_id', $userId)
                    ->where('is_active', 1),
            ],

            'trigger_type'             => ['required', 'in:schedule,deposit,balance,manual'],
            'trigger_config'           => ['required', 'array'],
            'trigger_config.frequency' => ['sometimes', 'nullable', 'string'],
            'trigger_config.time'      => ['sometimes', 'nullable'],
            'trigger_config.day'       => ['sometimes', 'nullable', 'integer'],

            'total_amount_type' => ['required', 'in:fixed,percentage,remainder'],
            'total_amount'      => ['nullable', 'integer'],

            'actions'               => ['required', 'array', 'min:1', 'max:15'],
            'actions.*.action_type' => ['required', 'string', 'in:send_bank,save_piggyvest,save_cowrywise,convert_crypto,pay_bill'],
            'actions.*.amount_type' => ['required', 'string', 'in:fixed,percentage,remainder'],
            'actions.*.amount'      => ['required', 'numeric'],
            'actions.*.label'       => ['sometimes', 'nullable', 'string', 'max:80'],
            'actions.*.config'      => ['sometimes', 'nullable', 'array'],
            'actions.*.step_order'  => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                  => 'Rule name is required.',
            'connected_account_id.required'  => 'Select an account to debit from.',
            'connected_account_id.exists'    => 'Account not found or does not belong to your profile.',
            'trigger_type.required'          => 'A trigger type is required.',
            'trigger_type.in'                => 'Invalid trigger type. Valid: schedule, deposit, balance, manual.',
            'actions.required'               => 'At least one action is required.',
            'actions.*.action_type.in'       => 'Invalid action type. Valid: send_bank, save_piggyvest, save_cowrywise, convert_crypto, pay_bill.',
            'actions.*.amount_type.in'       => 'Invalid amount type. Valid: fixed, percentage, remainder.',
            'actions.*.amount.required'      => 'Each action must have an amount.',
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
