<?php

declare(strict_types=1);

/*
 * Copy for the four §1.2 CRM screens (S2-09).
 *
 * Two rules the wording follows and `tests/Feature/CRM/CrmWorksWithoutAiTest.php`
 * keeps: nothing here says the CRM needs AI, and nothing here claims AI or the
 * scheduler runs anywhere but the Mac (§1.2, §4.2). A string that said "ask the
 * assistant to…" would make a working screen look broken while a provider is
 * unconfigured.
 */

return [
    'nav' => 'CRM',

    'customers' => [
        'title' => 'Customers',
        'lead' => 'The organizations you work with, their legal identity and who owns the relationship.',
        'empty' => 'No customers yet',
        'empty_body' => 'Add the first customer, or import a workspace from a JSON pack.',
        'empty_search' => 'Nothing matches that search',
        'empty_search_body' => 'Every word has to match. Try one word, or add a * to the end to match a prefix.',
        'new' => 'New customer',
        'fields' => [
            'display_name' => 'Name',
            'legal_name' => 'Registered name',
            'country' => 'Country',
            'registration_number' => 'Registration number',
            'website' => 'Website',
            'email_domains' => 'Email domains',
            'email_domains_hint' => 'Comma separated. A hint for matching, never a key: two customers may share a domain.',
            'tags' => 'Tags',
            'tags_hint' => 'Comma separated.',
            'owner' => 'Relationship owner',
        ],
    ],

    'contacts' => [
        'title' => 'Contacts',
        'lead' => 'The people at those organizations, with every address they write from.',
        'empty' => 'No contacts yet',
        'empty_body' => 'Add the first person, or import a workspace from a JSON pack.',
        'empty_search' => 'Nothing matches that search',
        'empty_search_body' => 'Names and notes are searched; email addresses are not. Try a name.',
        'new' => 'New contact',
        'addresses' => 'Email addresses',
        'addresses_hint' => 'A person may write from several. The primary one is shown first.',
        'add_address' => 'Add an address',
        'remove_address' => 'Remove this address',
        'make_primary' => 'Make primary',
        'primary' => 'Primary',
        'customers' => 'Belongs to',
        'customers_hint' => 'A person can belong to more than one customer.',
        'no_addresses' => 'No address recorded',
        'fields' => [
            'given_name' => 'First name',
            'family_name' => 'Family name',
            'display_name' => 'Shown as',
            'title' => 'Job title',
            'language' => 'Language',
            'address' => 'Email address',
            'label' => 'Label',
        ],
        'labels' => [
            'work' => 'Work',
            'personal' => 'Personal',
            'other' => 'Other',
        ],
        'errors' => [
            'duplicate_address' => 'This address is already in the list.',
            'address_taken' => 'Another contact in this workspace already uses this address.',
        ],
    ],

    'projects' => [
        'title' => 'Projects',
        'lead' => 'What is running for each customer, with its status and its deadline.',
        'empty' => 'No projects yet',
        'empty_body' => 'A project belongs to a customer, so add a customer first if there is none.',
        'empty_search' => 'Nothing matches that search',
        'empty_search_body' => 'Projects are searched through their customer. Try the customer name.',
        'new' => 'New project',
        'fields' => [
            'name' => 'Project',
            'starts_on' => 'Starts on',
            'due_on' => 'Due on',
        ],
    ],

    'tasks' => [
        'title' => 'Tasks',
        'lead' => 'What you owe people, including the follow-ups you promised.',
        'empty' => 'No tasks yet',
        'empty_body' => 'Add what you owe someone. A task needs no deadline unless one was agreed.',
        'empty_search' => 'Nothing matches that search',
        'empty_search_body' => 'Tasks are searched through their customer. Try the customer name.',
        'new' => 'New task',
        'no_due_date' => 'No deadline',
        'fields' => [
            'title' => 'Task',
            'priority' => 'Priority',
            'due_at' => 'Due',
        ],
    ],

    'fields' => [
        'status' => 'Status',
        'notes' => 'Notes',
        'description' => 'Description',
        'customer' => 'Customer',
        'project' => 'Project',
        'contact' => 'Contact',
        'updated' => 'Updated',
        'none' => 'None',
    ],

    'status' => [
        'customer' => [
            'prospect' => 'Prospect',
            'active' => 'Active',
            'dormant' => 'Dormant',
            'churned' => 'Churned',
        ],
        'project' => [
            'planned' => 'Planned',
            'active' => 'Active',
            'on_hold' => 'On hold',
            'done' => 'Done',
            'cancelled' => 'Cancelled',
        ],
        'task' => [
            'todo' => 'To do',
            'doing' => 'Doing',
            'blocked' => 'Blocked',
            'done' => 'Done',
        ],
    ],

    'search' => [
        'label' => 'Search',
        'placeholder' => 'Search this workspace',
        'hint' => 'Every word must match. Add * to the end of a word to match a prefix.',
    ],

    'filters' => [
        'any_status' => 'Any status',
        'any_customer' => 'Any customer',
        'show_deleted' => 'Show deleted',
        'clear' => 'Clear filters',
    ],

    'actions' => [
        'open' => 'Open',
        'edit' => 'Edit',
        'save' => 'Save',
        'saving' => 'Saving…',
        'cancel' => 'Cancel',
        'close' => 'Close',
        'delete' => 'Delete',
        'approve_delete' => 'Approve deletion',
        'withdraw_delete' => 'Withdraw',
        'restore' => 'Restore',
    ],

    'deletion' => [
        'proposed' => 'Deletion proposed. It is not deleted until the deletion is approved.',
        'done' => 'Deleted. It can be restored until the retention rule removes it.',
        'withdrawn' => 'The proposed deletion was withdrawn. Nothing was deleted.',
        'awaiting' => 'Awaiting approval',
        'awaiting_body' => 'A deletion has been proposed for this record. Nothing is deleted until it is approved — that is what makes who deleted what reviewable afterwards.',
        'expires' => 'The proposal lapses :when.',
        'deleted_badge' => 'Deleted',
    ],

    'saved' => [
        'created' => 'Created.',
        'updated' => 'Saved.',
    ],

    'restored' => 'Restored.',

    'pagination' => [
        'previous' => 'Previous',
        'next' => 'Next',
        'summary' => 'Showing :from–:to of :total',
    ],

    'no_workspace' => [
        'title' => 'No workspace yet',
        'body' => 'This account is signed in but belongs to no workspace, so there is nothing to show. Nothing is wrong with the records; there are none to read.',
    ],
];
