<?php

namespace App\Http\Controllers;

use App\Enums\JobListingStatus;
use App\Http\Requests\StoreJobListingRequest;
use App\Http\Requests\UpdateJobListingRequest;
use App\Models\JobListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class JobListingController extends Controller
{
    public function index(): View
    {
        $jobListings = JobListing::with('organization')
            ->orderByDesc('created_at')
            ->get();

        return view('job-listings.index', [
            'jobListings' => $jobListings,
        ]);
    }

    public function create(): View
    {
        return view('job-listings.create', [
            'jobListing' => new JobListing(),
            'statuses' => JobListingStatus::cases(),
        ]);
    }

    public function store(StoreJobListingRequest $request): RedirectResponse
    {
        $jobListing = JobListing::create($request->validated());

        return redirect()
            ->route('job-listings.show', $jobListing)
            ->with('status', "Job listing \"{$jobListing->role_title}\" created.");
    }

    public function show(JobListing $jobListing): View
    {
        $jobListing->load('organization', 'resumeDrafts');

        return view('job-listings.show', [
            'jobListing' => $jobListing,
        ]);
    }

    public function edit(JobListing $jobListing): View
    {
        $jobListing->load('organization');

        return view('job-listings.edit', [
            'jobListing' => $jobListing,
            'statuses' => JobListingStatus::cases(),
        ]);
    }

    public function update(
        UpdateJobListingRequest $request,
        JobListing $jobListing,
    ): RedirectResponse {
        $jobListing->update($request->validated());

        return redirect()
            ->route('job-listings.show', $jobListing)
            ->with('status', "Job listing \"{$jobListing->role_title}\" updated.");
    }

    public function destroy(JobListing $jobListing): RedirectResponse
    {
        $title = $jobListing->role_title;
        $jobListing->delete();

        return redirect()
            ->route('job-listings.index')
            ->with('status', "Job listing \"{$title}\" deleted.");
    }
}