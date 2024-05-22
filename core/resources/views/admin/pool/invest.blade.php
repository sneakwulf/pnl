@extends('admin.layouts.app')
@section('panel')
    <div class="row">
        <div class="col-lg-12">
            <div class="card b-radius--10 ">
                <div class="card-body p-0">
                    <div class="table-responsive--md  table-responsive">
                        <table class="table table--light style--two">
                            <thead>
                                <tr>
                                    <th>@lang('User')</th>
                                    <th>@lang('Pool Name')</th>
                                    <th>@lang('Invest Amount')</th>
                                    <th>@lang('Interest Given')</th>
                                    <th>@lang('Status')</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($poolInvests as $poolInvest)
                                    <tr>
                                        <td>
                                            <span class="fw-bold">{{ $poolInvest->user->fullname }}</span>
                                            <br>
                                            <span class="small">
                                                <a href="{{ route('admin.users.detail', $poolInvest->user->id) }}"><span>@</span>{{ $poolInvest->user->username }}</a>
                                            </span>
                                        </td>
                                        <td>{{ __($poolInvest->pool->name) }}</td>
                                        <td>{{ $general->cur_sym }}{{ showAmount($poolInvest->invest_amount) }}</td>
                                        <td>
                                            @if ($poolInvest->pool->share_interest)
                                                {{ $general->cur_sym }}{{ showAmount($poolInvest->invest_amount * $poolInvest->pool->interest / 100) }}
                                            @else
                                                @lang('No return yet!')
                                            @endif
                                        </td>
                                        <td>
                                            @if ($poolInvest->status == 1)
                                                <span class="badge badge--success">@lang('Running')</span>
                                            @else
                                                <span class="badge badge--primary">@lang('Completed')</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="text-muted text-center" colspan="100%">{{ __($emptyMessage) }}</td>
                                    </tr>
                                @endforelse

                            </tbody>
                        </table><!-- table end -->
                    </div>
                </div>
                @if ($poolInvests->hasPages())
                    <div class="card-footer py-4">
                        {{ paginateLinks($poolInvests) }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <x-confirmation-modal />
@endsection

@push('breadcrumb-plugins')
    @if (!request()->routeIs('admin.users.deposits') && !request()->routeIs('admin.users.deposits.method'))
        <x-search-form placeholder="Username / Pool name" />
    @endif
@endpush
