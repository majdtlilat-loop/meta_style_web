<?php

declare(strict_types=1);

/*
 * Manager › Services — the service library (categories, services, variations,
 * photos, ordering). Group named after its SURFACE, never after a dotless
 * literal such as "Services" (SafeguardsTest).
 */
return [
    'title' => 'Services',

    'summary' => [
        'services' => '{0} No services|{1} :count service|[2,*] :count services',
        'categories' => '{0} no categories|{1} :count category|[2,*] :count categories',
    ],

    'actions' => [
        'add_service' => 'Add service',
        'add_category' => 'Add category',
        'duplicate' => 'Duplicate',
        'move_to_category' => 'Move to category…',
        'hide' => 'Hide from menu',
        'show' => 'Show on menu',
        'online_on' => 'Allow online booking',
        'online_off' => 'Stop online booking',
    ],

    'categories' => [
        'title' => 'Categories',
        'all' => 'All services',
        'uncategorised' => 'Uncategorised',
        'drag' => 'Drag to reorder :name',
        'actions' => 'More actions for :name',
        'edit' => 'Edit category',
        'archive_title' => 'Archive category',
        'archive_confirm' => '{0} Archive “:name”? It leaves the menu.|{1} Archive “:name”? Its :count service stays and becomes uncategorised.|[2,*] Archive “:name”? Its :count services stay and become uncategorised.',
        'archived' => 'Archived categories (:count)',
    ],

    'ordering' => [
        'drag_hint' => 'Drag, or use the arrow keys',
        'move_up' => 'Move :name up',
        'move_down' => 'Move :name down',
        'categories_hint' => 'Drag a service onto a category to move it there.',
        'filtered' => 'Clear the search and filters to reorder services.',
    ],

    'states' => [
        'hidden' => 'Hidden',
        'hidden_hint' => 'Not shown on the public menu',
        'offline' => 'No online booking',
        'offline_hint' => 'Customers cannot book this online',
        'on_menu' => 'On the menu',
    ],

    'filters' => [
        'label' => 'Find services',
        'search' => 'Search services',
        'status' => 'Status',
        'status_all' => 'All',
        'visibility' => 'Visibility',
        'visibility_any' => 'Any visibility',
        'visibility_public' => 'On the menu',
        'visibility_hidden' => 'Hidden from the menu',
        'visibility_online' => 'Bookable online',
        'visibility_offline' => 'Not bookable online',
    ],

    'empty' => [
        'library_title' => 'Build your service library',
        'no_matches_title' => 'No services match',
    ],

    'sections' => [
        'empty' => 'No services here yet.',
        'empty_drop' => 'No services here yet — add one, or drag one in.',
    ],

    'move' => [
        'title' => 'Move “:name”',
        'target' => 'Category',
        'current' => 'current',
        'submit' => 'Move',
    ],

    'services' => [
        'variations' => '{1} :count variation|[2,*] :count variations',
        'from' => 'from',
        'drag' => 'Drag to reorder :name',
        'edit' => 'Edit :name',
        'actions' => 'More actions for :name',
        'archive_title' => 'Archive service',
        'archive_confirm' => 'Archive “:name”? It leaves the menu and bookings; past invoices keep it.',
        'restore_hint' => 'Comes back inactive and hidden, so you can check its price first.',
        'copy_name' => ':name (copy)',
    ],

    'duration' => [
        'minutes' => ':count min',
        'hours' => ':count h',
        'hours_minutes' => ':hours h :minutes min',
    ],

    'notices' => [
        'created' => 'Service added.',
        'created_add_photos' => 'Service added. You can add photos now, or close.',
        'saved' => 'Service saved.',
        'duplicated' => 'Copy created — inactive and hidden until you review it.',
        'activated' => 'Service activated.',
        'deactivated' => 'Service deactivated.',
        'shown' => 'Service is on the menu.',
        'hidden' => 'Service is hidden from the menu.',
        'online_on' => 'Online booking allowed.',
        'online_off' => 'Online booking stopped.',
        'archived' => 'Service archived.',
        'restored' => 'Service restored — inactive and hidden.',
        'moved' => 'Service moved.',
        'category_created' => 'Category added.',
        'category_created_image' => 'Category added. You can add its image now, or close.',
        'category_saved' => 'Category saved.',
        'category_archived' => 'Category archived. Its services are now uncategorised.',
        'category_restored' => 'Category restored — inactive and hidden.',
    ],

    'errors' => [
        'not_found' => 'That item no longer exists. The list has been refreshed.',
        'name' => 'Enter a name.',
        'variation_name' => 'Enter a name for this variation.',
        'duration' => 'Enter a duration between 1 and 1,440 minutes.',
        'price_range' => 'That price is outside the allowed range.',
        'branches' => 'Choose at least one branch, or make it available at every branch.',
        'department' => 'That department is no longer available.',
        'category' => 'That category is archived or no longer available.',
        'service_archived' => 'This service is archived. Restore it first.',
        'requirements' => 'Check the resource requirements: each type once, a quantity from 1 to 255, active types only.',
        'media_invalid' => 'Use a JPEG, PNG or WebP image of at most 5 MB and 4,000 px on each side.',
        'media_limit' => 'You have reached the photo limit.',
    ],

    'fields' => [
        'name' => 'Name',
        'summary' => 'Short description',
        'description' => 'Description',
        'price' => 'Price',
        'duration' => 'Duration',
        'category' => 'Category',
        'department' => 'Department',
        'active' => 'Active',
        'public' => 'Show on the public menu',
        'online' => 'Bookable online',
        'all_branches' => 'Available at every branch',
        'branches' => 'Branches',
        'variation_name' => 'Variation name',
        'resource_type' => 'Resource type',
        'quantity' => 'Quantity',
        'photo' => 'photo',
    ],

    'editor' => [
        'create_title' => 'New service',
        'edit_title' => 'Edit :name',
        'create' => 'Create service',
        'minutes' => 'min',
        'sections' => [
            'details' => 'Details',
            'price' => 'Price and duration',
            'availability' => 'Availability',
            'team' => 'Who can perform it',
            'variations' => 'Variations',
            'photos' => 'Photos',
            'resources' => 'Resource requirements',
        ],
        'department_help' => 'Routes the work — queue, rooms, journey.',
        'active_help' => 'Inactive services cannot be sold or booked.',
        'public_help' => 'Hidden services can still be sold at the till.',
        'online_locked' => 'Your plan has no online booking yet; this is kept for when it does.',
        'no_staff' => 'No active staff yet.',
        'add_variation' => 'Add variation',
        'drag_variation' => 'Drag to reorder variation :number',
        'move_variation_up' => 'Move variation :number up',
        'move_variation_down' => 'Move variation :number down',
        'remove_variation' => 'Remove variation :number',
        'follows' => 'Follows the service (:value)',
        'photos_after_save' => 'Create the service first, then add up to eight photos here.',
        'add_requirement' => 'Add requirement',
        'no_requirements' => 'No resources needed.',
        'choose_resource' => 'Choose a resource type',
        'remove_requirement' => 'Remove requirement',
    ],

    'category_editor' => [
        'create_title' => 'New category',
        'edit_title' => 'Edit :name',
        'create' => 'Create category',
        'active_help' => 'Inactive categories stay in the library but leave the menu.',
        'image' => 'Image',
        'image_after_save' => 'Create the category first, then add its image here.',
        'archive_hint' => 'It leaves the menu. Its services stay and become uncategorised.',
        'archive_confirm' => 'Archive this category? Its services stay and become uncategorised.',
    ],

    'media' => [
        'add_photos' => 'Add photos',
        'add_image' => 'Add image',
        'replace_image' => 'Replace image',
        'rules_gallery' => 'JPEG, PNG or WebP · up to 5 MB · up to :count photos',
        'rules_single' => 'JPEG, PNG or WebP · up to 5 MB',
        'uploading' => 'Uploading…',
        'uploaded' => '{1} Photo added.|[2,*] :count photos added.',
        'removed' => 'Photo removed.',
        'remove' => 'Remove photo :number',
        'remove_title' => 'Remove photo',
        'remove_confirm' => 'Remove this photo? It is deleted for good.',
        'cover' => 'Cover',
        'make_cover' => 'Use photo :number as the cover',
        'make_cover_short' => 'Use as cover',
        'drag' => 'Drag to reorder photo :number',
        'move_earlier' => 'Move photo :number earlier',
        'move_later' => 'Move photo :number later',
        'limit' => 'A service can have up to :count photos.',
        'full' => 'All :count photo places are used. Remove one to add another.',
        'none' => 'No photos yet.',
    ],

    'quick' => [
        'label' => 'Quick add',
        'name' => 'New service in :category',
        'submit' => 'Add',
    ],
];
