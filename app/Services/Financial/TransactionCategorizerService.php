<?php

namespace App\Services\Financial;

use App\Enums\TransactionType;

class TransactionCategorizerService
{
  /**
   * Category keyword patterns — ordered by specificity.
   * Each entry: [ 'category', 'sub_category', [keywords] ]
   */
  private array $patterns = [
    // Salary — highest priority
    ['salary',      'salary',          ['salary', 'salry', 'payroll', 'pay roll', 'monthly pay', 'staff pay', 'emolument', 'remuneration', 'wages']],

    // Family transfers
    ['family',      'family_transfer', ['mama', 'mum ', 'mom ', 'dad ', 'daddy', 'papa', 'father', 'mother', 'brother', 'sister', 'uncle', 'aunty', 'auntie']],

    // Ajo / Esusu
    ['savings',     'ajo',             ['ajo', 'esusu', 'thrift', 'contribution', 'cooperative']],

    // Bills
    ['bills',       'electricity',     ['dstv', 'gotv', 'startimes', 'showmax', 'cable', 'subscription']],
    ['bills',       'electricity',     ['nepa', 'phcn', 'ekedc', 'aedc', 'ikedc', 'phedc', 'jedc', 'bedc', 'kedco', 'electricity', 'prepaid meter']],
    ['bills',       'airtime_data',    ['airtime', 'data bundle', 'mtn', 'airtel', 'glo', '9mobile', 'etisalat']],
    ['bills',       'internet',        ['spectranet', 'smile', 'ipnx', 'swift', 'internet', 'broadband', 'wifi']],

    // Food
    ['food',        'restaurant',      ['restaurant', 'kitchen', 'eatery', 'bukka', 'buka', 'canteen', 'suya', 'shawarma', 'dominos', 'chicken republic', 'tantalizers', 'mr biggs']],
    ['food',        'groceries',       ['shoprite', 'spar', 'justrite', 'supermarket', 'grocery', 'market', 'foodstuff', 'provision']],
    ['food',        'food_delivery',   ['jumia food', 'bolt food', 'glovo', 'chowdeck', 'food delivery']],

    // Transport
    ['transport',   'ride_hailing',    ['uber', 'bolt', 'taxify', 'indriver', 'ride']],
    ['transport',   'fuel',            ['petrol', 'fuel', 'filling station', 'nnpc', 'total', 'mobil', 'oando', 'ardova']],
    ['transport',   'bus_rail',        ['brt', 'bus', 'train', 'terminal', 'park', 'transport']],

    // Shopping
    ['shopping',    'ecommerce',       ['jumia', 'konga', 'amazon', 'aliexpress', 'payporte']],
    ['shopping',    'fashion',         ['zara', 'h&m', 'next', 'fashion', 'clothing', 'shoes', 'boutique']],
    ['shopping',    'electronics',     ['slot', 'pointek', 'fouani', 'electronics', 'gadget', 'phone']],

    // Healthcare
    ['health',      'pharmacy',        ['pharmacy', 'chemist', 'drugs', 'medplus', 'health plus']],
    ['health',      'hospital',        ['hospital', 'clinic', 'medical', 'doctor', 'lab', 'test', 'scan']],

    // Education
    ['education',   'school_fees',     ['school fees', 'tuition', 'university', 'polytechnic', 'college', 'school', 'nis ', 'waec', 'jamb', 'neco']],
    ['education',   'books',           ['bookshop', 'books', 'stationery']],

    // Financial services
    ['financial',   'bank_charges',    ['bank charge', 'maintenance fee', 'sms alert', 'card maintenance', 'account maintenance', 'stamp duty']],
    ['financial',   'loan_repayment',  ['loan', 'repayment', 'carbon', 'fairmoney', 'branch', 'renmoney', 'palmcredit', 'quickcheck']],
    ['financial',   'investment',      ['piggyvest', 'cowrywise', 'risevest', 'bamboo', 'trove', 'investment', 'mutual fund']],
    ['financial',   'insurance',       ['insurance', 'premium', 'axa', 'leadway', 'aiico', 'custodian']],

    // Rent / Housing
    ['housing',     'rent',            ['rent', 'landlord', 'caution', 'agency fee', 'tenancy']],
    ['housing',     'utilities',       ['water', 'waste', 'sewage', 'sanitation', 'service charge']],

    // Entertainment
    ['entertainment', 'streaming',      ['netflix', 'spotify', 'apple music', 'youtube premium', 'boomplay']],
    ['entertainment', 'events',         ['event', 'concert', 'ticket', 'cinema', 'filmhouse', 'genesis']],

    // Transfers — catch-all for peer-to-peer
    ['transfer',    'peer_transfer',   ['transfer to', 'trf to', 'transfer from', 'trf from', 'payment to', 'payment from']],

    // POS / ATM
    ['cash',        'atm_withdrawal',  ['atm withdrawal', 'cash withdrawal', 'pos withdrawal']],
    ['cash',        'pos_purchase',    ['pos purchase', 'pos payment', 'pos debit']],
  ];

  /**
   * Categorise a transaction based on its narration.
   */
  public function categorise(string $narration, TransactionType $type, int $amountKobo): array
  {
    $lower = strtolower($narration);

    // Check for Atlas-generated execution marker
    if (str_contains($lower, 'atlas') || str_contains($lower, 'atl-')) {
      return $this->result('financial', 'atlas_execution', false, false, false, false, true, 0.99);
    }

    // Run pattern matching
    foreach ($this->patterns as [$category, $subCategory, $keywords]) {
      foreach ($keywords as $keyword) {
        if (str_contains($lower, $keyword)) {
          $isSalary         = $category === 'salary' && $type === TransactionType::Credit;
          $isFamilyTransfer = $category === 'family';
          $isAjo            = $subCategory === 'ajo';
          $isBillPayment    = $category === 'bills';

          return $this->result(
            $category,
            $subCategory,
            $isSalary,
            $isFamilyTransfer,
            $isAjo,
            $isBillPayment,
            false,
            0.90
          );
        }
      }
    }

    // Salary heuristic — large credit on known salary days
    if ($type === TransactionType::Credit && $amountKobo >= 5000000) { // >= N50,000
      $day = now()->day;
      if ($day >= 24 && $day <= 31) {
        return $this->result('salary', 'probable_salary', true, false, false, false, false, 0.65);
      }
    }

    // Default — uncategorised
    $defaultCategory = $type === TransactionType::Credit ? 'income' : 'uncategorised';

    return $this->result($defaultCategory, null, false, false, false, false, false, 0.30);
  }

  private function result(
    string $category,
    ?string $subCategory,
    bool $isSalary,
    bool $isFamilyTransfer,
    bool $isAjo,
    bool $isBillPayment,
    bool $isAtlasExecution,
    float $confidence
  ): array {
    return [
      'category'           => $category,
      'sub_category'       => $subCategory,
      'description'        => null,
      'is_salary'          => $isSalary,
      'is_family_transfer' => $isFamilyTransfer,
      'is_ajo'             => $isAjo,
      'is_bill_payment'    => $isBillPayment,
      'is_atlas_execution' => $isAtlasExecution,
      'confidence'         => $confidence,
    ];
  }
}
