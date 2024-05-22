<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Controller;
use App\Lib\HyipLab;
use App\Models\GatewayCurrency;
use App\Models\Invest;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\PoolInvest;
use App\Models\ScheduleInvest;
use App\Models\Staking;
use App\Models\StakingInvest;
use App\Models\Transaction;
use App\Models\UserRanking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvestController extends Controller
{
    public function invest()
    {
        $myInvests      = Invest::with('plan')->where('user_id', auth()->id());
        $notify         = 'My Invests';
        $modifiedInvest = [];

        if (request()->type == 'active') {
            $myInvests = $myInvests->where('status', 1);
            $notify    = 'My Active Invests';
        } elseif (request()->type == 'closed') {
            $myInvests = $myInvests->where('status', 0);
            $notify    = 'My Closed Invests';
        }

        $myInvests = $myInvests->apiQuery();

        if (!request()->calc) {
            $modifyInvest = [];

            foreach ($myInvests as $invest) {

                if ($invest->last_time) {
                    $start = $invest->last_time;
                } else {
                    $start = $invest->created_at;
                }

                $modifyInvest[] = [
                    'id'                => $invest->id,
                    'user_id'           => $invest->user_id,
                    'plan_id'           => $invest->plan_id,
                    'amount'            => $invest->amount,
                    'interest'          => $invest->interest,
                    'should_pay'        => $invest->should_pay,
                    'paid'              => $invest->paid,
                    'period'            => $invest->period,
                    'hours'             => $invest->hours,
                    'time_name'         => $invest->time_name,
                    'return_rec_time'   => $invest->return_rec_time,
                    'next_time'         => $invest->next_time,
                    'next_time_percent' => getAmount(diffDatePercent($start, $invest->next_time)),
                    'status'            => $invest->status,
                    'capital_status'    => $invest->capital_status,
                    'capital_back'      => $invest->capital_back,
                    'wallet_type'       => $invest->wallet_type,
                    'plan'              => $invest->plan,
                ];
            }

            if (request()->take) {
                $modifiedInvest = [
                    'data' => $modifyInvest,
                ];
            } else {
                $modifiedInvest = [
                    'data'      => $modifyInvest,
                    'next_page' => $myInvests->nextPageUrl(),
                ];
            }

        } else {
            $modifiedInvest = $myInvests;
        }

        return response()->json([
            'remark'  => 'my_invest',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'invests' => $modifiedInvest,
            ],
        ]);
    }

    public function details($id)
    {
        $invest = Invest::with('user', 'plan')->where('user_id', auth()->id())->find($id);
        
        if (!$invest) {
            return getResponse('not_found', 'error', 'Investment not found');
        }
        
        $transactions = Transaction::where('invest_id', $invest->id)->orderBy('id', 'desc')->paginate(getPaginate());
        
        return getResponse('not_found', 'error', 'Investment not found', ['invest' => $invest, 'transactions' => $transactions]);
    }


    public function storeInvest(Request $request)
    {
        $validator = $this->validation($request);
        if ($validator->fails()) {
            return getResponse('validation_error', 'error', $validator->errors()->all());
        }

        $amount = $request->amount;
        $wallet = $request->wallet;
        $user   = auth()->user();

        $plan = Plan::with('timeSetting')->whereHas('timeSetting', function ($time) {
            $time->where('status', 1);
        })->where('status', 1)->find($request->plan_id);

        if (!$plan) {
            return getResponse('not_found', 'error', 'Plan not found');
        }

        $planValidation = $this->planInfoValidation($plan, $request);

        if (is_array($planValidation)) {
            return getResponse(key($planValidation), 'error', [current($planValidation)]);
        }

        if ($request->invest_time == 'schedule' && gs('schedule_invest')) {
            HyipLab::saveScheduleInvest($request);
            return getResponse('invest_scheduled', 'success', 'Invest scheduled successfully');
        }

        if ($wallet != 'deposit_wallet' && $wallet != 'interest_wallet') {
            $gate = GatewayCurrency::whereHas('method', function ($gate) {
                $gate->where('status', 1);
            })->where('method_code', $wallet)->first();

            if (!$gate) {
                return getResponse('not_found', 'error', 'Gateway not found');
            }

            if ($gate->min_amount > $amount || $gate->max_amount < $amount) {
                return getResponse('limit_error', 'error', 'Please follow deposit limit');
            }

            $deposit = PaymentController::insertDeposit($gate, $amount, $plan, $request->compound_interest);
            $data    = [
                'redirect_url' => route('deposit.app.confirm', encrypt($deposit->id)),
            ];

            return getResponse('deposit_success', 'success', 'Invest deposit successfully', $data);
        }

        if ($user->$wallet < $amount) {
            return getResponse('insufficient_balance', 'error', 'Insufficient balance');
        }

        $hyip = new HyipLab($user, $plan);
        $hyip->invest($amount, $wallet, $request->compound_interest);

        return getResponse('invested', 'success', 'Invested to plan successfully');
    }

    private function validation($request)
    {
        $validationRule = [
            'amount'            => 'required|numeric|gt:0',
            'plan_id'           => 'required|integer',
            'wallet'            => 'required',
            'compound_interest' => 'nullable|numeric|min:0',
        ];

        $general = gs();

        if ($general->schedule_invest) {
            $validationRule['invest_time'] = 'required|in:invest_now,schedule';
        }

        if ($request->invest_time == 'schedule') {
            $validationRule['wallet']         = 'required|in:deposit_wallet,interest_wallet';
            $validationRule['schedule_times'] = 'required|integer|min:1';
            $validationRule['hours']          = 'required|integer|min:1';
        }

        $validator = Validator::make($request->all(), $validationRule, [
            'wallet.in' => 'For schedule invest wallet must be deposit wallet or interest wallet',
        ]);

        return $validator;
    }

    private function planInfoValidation($plan, $request)
    {
        if ($request->compound_interest) {
            if (!$plan->compound_interest) {
                return ['not_available' => 'Compound interest optional is not available for this plan.'];
            }

            if ($plan->repeat_time && $plan->repeat_time <= $request->compound_interest) {
                return ['limit_exceeded' => 'Compound interest times must be fewer than repeat times.'];
            }
        }

        if ($plan->fixed_amount > 0) {
            if ($request->amount != $plan->fixed_amount) {
                return ['limit_error' => 'Please check the investment limit'];
            }
        } else {
            if ($request->amount < $plan->minimum || $request->amount > $plan->maximum) {
                return ['limit_error' => 'Please check the investment limit'];
            }
        }
        return 'no_plan_validation_error_found';
    }
    
    public function manageCapital(Request $request)
    {
        $request->validate([
            'invest_id' => 'required|integer',
            'capital'   => 'required|in:reinvest,capital_back',
        ]);
    
        $user   = auth()->user();
        $invest = Invest::with('user')->where('user_id', $user->id)->where('capital_status', 1)->where('capital_back', 0)->where('status', 0)->find($request->invest_id);

        if (!$invest) {
            return getResponse('not_found', 'error', 'Investment not found');
        }

        if ($request->capital == 'capital_back') {
            HyipLab::capitalReturn($invest);
            return getResponse('capital_added', 'success', 'Capital added to your wallet successfully');
        }

        $plan = Plan::whereHas('timeSetting', function ($timeSetting) {
            $timeSetting->where('status', 1);
        })->where('status', 1)->find($invest->plan_id);

        if (!$plan) {
            return getResponse('not_available', 'error', 'This plan currently unavailable');
        }

        HyipLab::capitalReturn($invest);
        $hyip = new HyipLab($user, $plan);
        $hyip->invest($invest->amount, 'interest_wallet', $invest->compound_times);

        return getResponse('reinvest_success', 'success', 'Reinvested to plan successfully');
    }

    public function allPlans()
    {
        $plans = Plan::with('timeSetting')->whereHas('timeSetting', function ($time) {
            $time->where('status', 1);
        })->where('status', 1)->get();
        $modifiedPlans = [];
        $general       = gs();

        foreach ($plans as $plan) {
            if ($plan->lifetime == 0) {
                $totalReturn = 'Total ' . $plan->interest * $plan->repeat_time . ' ' . ($plan->interest_type == 1 ? '%' : $general->cur_text);
                $totalReturn = $plan->capital_back == 1 ? $totalReturn . ' + Capital' : $totalReturn;

                $repeatTime       = 'For ' . $plan->repeat_time . ' ' . $plan->timeSetting->name;
                $interestValidity = 'Per ' . $plan->timeSetting->time . ' hours for '.$plan->repeat_time . ' times';
            } else {
                $totalReturn      = 'Lifetime Earning';
                $repeatTime       = 'For Lifetime';
                $interestValidity = 'Per ' . $plan->timeSetting->time . ' hours for lifetime';
            }

            $modifiedPlans[] = [
                'id'                => $plan->id,
                'name'              => $plan->name,
                'minimum'           => $plan->minimum,
                'maximum'           => $plan->maximum,
                'fixed_amount'      => $plan->fixed_amount,
                'return'            => showAmount($plan->interest) . ' ' . ($plan->interest_type == 1 ? '%' : $general->cur_text),
                'interest_duration' => 'Every ' . $plan->timeSetting->name,
                'repeat_time'       => $repeatTime,
                'total_return'      => $totalReturn,
                'interest_validity' => $interestValidity,
                'hold_capital'      => $plan->hold_capital,
                'compound_interest' => $plan->compound_interest
            ];
        }

        $notify[] = 'All Plans';

        return response()->json([
            'remark'  => 'plan_data',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'plans' => $modifiedPlans,
            ],
        ]);
    }

    public function scheduleInvests(Request $request)
    {
        $general = gs();
        if (!$general->schedule_invest) {
            return getResponse('not_available', 'error', 'Schedule invest currently not available.');
        }
        $scheduleInvests = ScheduleInvest::with('plan.timeSetting')->where('user_id', auth()->id())->orderBy('id', 'desc')->apiQuery();
        
        
       $scheduleInvests->transform(function ($scheduleInvest) use($general) {
            $plan = $scheduleInvest['plan'];
            if ($plan->lifetime == 0) {
                $totalReturn = 'Total ' . $plan->interest * $plan->repeat_time . ' ' . ($plan->interest_type == 1 ? '%' : $general->cur_text);
                $totalReturn = $plan->capital_back == 1 ? $totalReturn . ' + Capital' : $totalReturn;

                $repeatTime       = 'For ' . $plan->repeat_time . ' ' . $plan->timeSetting->name;
                $interestValidity = 'Per ' . $plan->timeSetting->time . ' hours, ' . ' Per ' . $plan->repeat_time . ' ' . $plan->timeSetting->name;
                
            } else {
                $totalReturn      = 'Lifetime Earning';
                $repeatTime       = 'For Lifetime';
                $interestValidity = 'Per ' . $plan->timeSetting->time . ' hours, lifetime';
            }

            $scheduleInvest['plan']['return']            = showAmount($plan->interest) . ' ' . ($plan->interest_type == 1 ? '%' : $general->cur_text);
            $scheduleInvest['plan']['interest_duration'] = 'Every ' . $plan->timeSetting->name;
            $scheduleInvest['plan']['total_time']       = $repeatTime;
            $scheduleInvest['plan']['total_return']      = $totalReturn;
            $scheduleInvest['plan']['interest_validity'] = $interestValidity;
            
            $interest       = $plan->interest_type == 1 ? ($scheduleInvest->amount * $plan->interest) / 100 : $plan->interest;
            $scheduleReturn = showAmount($interest) .' '. __($general->cur_text) . ' every ' . $plan->timeSetting->name . ' for ' . ($plan->lifetime ? 'Lifetime' : $plan->repeat_time .' '. $plan->timeSetting->name) . ($plan->capital_back ? ' + Capital' : '');
            $scheduleInvest['return'] = $scheduleReturn;

            return $scheduleInvest;
        });
        

        return getResponse('schedule_invest', 'success', 'Schedule Invests', ['schedule_invests' => $scheduleInvests]);
    }

    public function scheduleStatus($id)
    {
        $scheduleInvest = ScheduleInvest::where('user_id', auth()->id())->find($id);
        if (!$scheduleInvest) {
            return getResponse('not_found', 'error', 'Schedule invest not found');
        }

        $scheduleInvest->status = !$scheduleInvest->status;
        $scheduleInvest->save();
        $notification = $scheduleInvest->status ? 'enabled' : 'disabled';

        return getResponse('status_changed', 'success', "Schedule invest $notification successfully");
    }

    public function staking()
    {
        if (!gs('staking_option')) {
            return getResponse('not_available', 'error', 'Staking currently not available.');
        }
        $stakings   = Staking::active()->get();
        $myStakings = StakingInvest::where('user_id', auth()->id())->orderBy('id', 'desc')->apiQuery();
        $data       = [
            'staking'     => $stakings,
            'my_stakings' => $myStakings,
        ];
        return getResponse('staking', 'success', 'Staking List', $data);
    }

    public function saveStaking(Request $request)
    {
        if (!gs('staking_option')) {
            return getResponse('not_available', 'error', 'Staking currently not available.');
        }

        $min = getAmount(gs('staking_min_amount'));
        $max = getAmount(gs('staking_max_amount'));

        $validator = Validator::make($request->all(), [
            'duration' => 'required|integer|min:1',
            'amount'   => "required|numeric|between:$min,$max",
            'wallet'   => 'required|in:deposit_wallet,interest_wallet',
        ]);

        if ($validator->fails()) {
            return getResponse('validation_error', 'error', $validator->errors()->all());
        }

        $user   = auth()->user();
        $wallet = $request->wallet;

        if ($user->$wallet < $request->amount) {
            return getResponse('insufficient_balance', 'error', 'Insufficient balance');
        }

        $staking = Staking::active()->find($request->duration);

        if (!$staking) {
            return getResponse('not_found', 'error', 'Staking not found');
        }

        $interest = $request->amount * $staking->interest_percent / 100;

        $stakingInvest                = new StakingInvest();
        $stakingInvest->user_id       = auth()->id();
        $stakingInvest->staking_id    = $staking->id;
        $stakingInvest->invest_amount = $request->amount;
        $stakingInvest->interest      = $interest;
        $stakingInvest->end_at        = now()->addDays($staking->days);
        $stakingInvest->save();

        $user->$wallet -= $request->amount;
        $user->save();

        $transaction               = new Transaction();
        $transaction->user_id      = $user->id;
        $transaction->amount       = $request->amount;
        $transaction->post_balance = $user->$wallet;
        $transaction->charge       = 0;
        $transaction->trx_type     = '-';
        $transaction->details      = 'Staking investment';
        $transaction->trx          = getTrx();
        $transaction->wallet_type  = $wallet;
        $transaction->remark       = 'staking_invest';
        $transaction->save();

        return getResponse('staking_save', 'success', 'Staking investment added successfully');
    }

    public function pools()
    {
        if (!gs('pool_option')) {
            return getResponse('not_available', 'error', 'Pool currently not available.');
        }

        $pools = Pool::active()->where('share_interest', 0)->get();
        return getResponse('pools', 'success', 'Pool List', ['pools' => $pools]);
    }

    public function poolInvests()
    {
        if (!gs('pool_option')) {
            return getResponse('not_available', 'error', 'Pool currently not available.');
        }
        $poolInvests = PoolInvest::with('pool')->where('user_id', auth()->id())->orderBy('id', 'desc')->apiQuery();
        
        $poolInvests->transform(function($poolInvest){
            if($poolInvest->pool->share_interest){
                $totalReturn = $poolInvest->invest_amount + ($poolInvest->pool->interest * $poolInvest->invest_amount / 100);
            }else{
                $totalReturn = 'Not return yet!';
            }
            $poolInvest->total_return = $totalReturn;
            return $poolInvest;
        });
        
        
        return getResponse('pool_invests', 'success', 'My Pool Invests', ['pool_invests' => $poolInvests]);
    }

    public function savePoolInvest(Request $request)
    {
        if (!gs('pool_option')) {
            return getResponse('not_available', 'error', 'Pool currently not available.');
        }

        $validator = Validator::make($request->all(), [
            'pool_id' => 'required|integer',
            'wallet'  => 'required|in:deposit_wallet,interest_wallet',
            'amount'  => 'required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return getResponse('validation_error', 'error', $validator->errors()->all());
        }

        $pool = Pool::active()->find($request->pool_id);

        if (!$pool) {
            return getResponse('not_found', 'error', 'Pool not found');
        }

        $user   = auth()->user();
        $wallet = $request->wallet;

        if ($pool->start_date <= now()) {
            return getResponse('date_over', 'error', 'The investment period for this pool has ended.');
        }

        if ($request->amount > $pool->amount - $pool->invested_amount) {
            return getResponse('limit_over', 'error', 'Pool invest over limit!');
        }

        if ($user->$wallet < $request->amount) {
            return getResponse('insufficient_balance', 'error', 'Insufficient balance');
        }

        $poolInvest = PoolInvest::where('user_id', $user->id)->where('pool_id', $pool->id)->where('status', 1)->first();

        if (!$poolInvest) {
            $poolInvest          = new PoolInvest();
            $poolInvest->user_id = $user->id;
            $poolInvest->pool_id = $pool->id;
        }

        $poolInvest->invest_amount += $request->amount;
        $poolInvest->save();

        $pool->invested_amount += $request->amount;
        $pool->save();

        $user->$wallet -= $request->amount;
        $user->save();

        $transaction               = new Transaction();
        $transaction->user_id      = $user->id;
        $transaction->amount       = $request->amount;
        $transaction->post_balance = $user->$wallet;
        $transaction->charge       = 0;
        $transaction->trx_type     = '-';
        $transaction->details      = 'Pool investment';
        $transaction->trx          = getTrx();
        $transaction->wallet_type  = $wallet;
        $transaction->remark       = 'pool_invest';
        $transaction->save();

        return getResponse('investment_successfully', 'success', 'Pool investment added successfully');
    }

    public function ranking()
    {
        if (!gs()->user_ranking) {
            return getResponse('not_available', 'error', 'User ranking currently not available.');
        }

        $userRankings = UserRanking::active()->get();
        $user         = auth()->user()->load('userRanking', 'referrals');
        $nextRanking  = UserRanking::active()->where('id', '>', $user->user_ranking_id)->first();
        $foundNext = 0;
        
        $userRankings->transform(function($userRanking) use($user, &$foundNext){
            if($user->user_ranking_id >= $userRanking->id){
                $userRanking->progress_percent = 100;
            }elseif(!$foundNext){
                $myInvestPercent = ($user->total_invests / $userRanking->minimum_invest) * 100;
                $refInvestPercent = ($user->team_invests / $userRanking->min_referral_invest) * 100;
                $refCountPercent = ($user->activeReferrals->count() / $userRanking->min_referral) * 100;
                
                $myInvestPercent = $myInvestPercent < 100 ? $myInvestPercent : 100;
                $refInvestPercent = $refInvestPercent < 100 ? $refInvestPercent : 100;
                $refCountPercent = $refCountPercent < 100 ? $refCountPercent : 100;
                $userRanking->progress_percent = ($myInvestPercent + $refInvestPercent + $refCountPercent) / 3;
                $foundNext = 1;
            }else{
                $userRanking->progress_percent = 0;
            }
            return $userRanking;
        });
        

        $data = [
            'user_rankings' => $userRankings,
            'next_ranking' => $nextRanking,
            'user'          => $user,
            'image_path' => getFilePath('userRanking')
        ];

        return getResponse('user_ranking', 'success', 'User rankings list', $data);
    }
}
