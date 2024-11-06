@extends('panelio::layout.layout')

@section('body')
    <x-list-view name="{{ $name }}" action="{{ $route }}" export-action="{{ $export_action }}" import-action="{{ $import_action }}">
        <x-slot name="filter">
            <div class="col-md-3">
                <div class="mb-5">
                    <label class="form-label">{{ trans('category::base.list.filters.name.title') }}</label>
                    <input type="text" name="translation[name]" class="form-control filter-list" id="filter-name" placeholder="{{ trans('category::base.list.filters.name.placeholder') }}" value="" autocomplete="off">
                </div>
            </div>
        </x-slot>

        <thead>
            <tr>
                <th width="1%">
                    <div class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input" type="checkbox" value="1" id="check-all"/>
                        <label class="form-check-label ms-0" for="check-all"></label>
                    </div>
                </th>
                <th width="64%" class="text-gray-800">نام</th>
                <th width="10%" class="text-center text-gray-800">{{ trans('category::base.list.columns.status') }}</th>
                <th width="10%" class="text-center text-gray-800">{{ trans('category::base.list.columns.ordering') }}</th>
                <th width="15%" class="text-center text-gray-800">{{ trans('category::base.list.columns.action') }}</th>
            </tr>
        </thead>
    </x-list-view>

    <h6 class="mt-10">{{ $description ?? '' }}</h6>
@endsection
