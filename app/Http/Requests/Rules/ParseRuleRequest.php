<?php

namespace App\Http\Requests\Rules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ParseRuleRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'rule_text' => ['required', 'string', 'min:5', 'max:500'],
    ];
  }

  public function messages(): array
  {
    return [
      'rule_text.required' => 'Please describe your rule.',
      'rule_text.min'      => 'Rule description is too short.',
      'rule_text.max'      => 'Rule description must be under 500 characters.',
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
