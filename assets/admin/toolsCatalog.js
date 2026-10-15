/**
 * Catalog of tools with human descriptions, realistic sample prompts for AI clients,
 * ready-to-run sample JSON arguments for "Try a tool", and expected HTTP 200 responses.
 *
 * Indexed by product slug and ability name.
 */
import { __ } from '@wordpress/i18n';

export const productToolsCatalog = {
	'real-testimonials': [
		{
			name: 'real-testimonials/get-context',
			label: __( 'Get Context', 'shaped-kit' ),
			summary: __( 'Inspects site environment, current user capability, and total testimonial counts.', 'shaped-kit' ),
			prompts: [
				__( 'Check our Real Testimonials setup and tell me how many testimonials we have.', 'shaped-kit' ),
				__( 'What abilities and permissions are available for Real Testimonials on this site?', 'shaped-kit' ),
				__( 'Show the overview of published and draft testimonials.', 'shaped-kit' ),
			],
			sampleArgs: {},
			samplePresets: [
				{
					label: __( 'System & Capability Check', 'shaped-kit' ),
					args: {},
				},
			],
			expectedOutput: {
				user_id: 1,
				user_login: 'admin',
				can_manage: true,
				edition: 'pro',
				timezone: 'UTC',
				counts: {
					testimonials: { publish: 12, draft: 2, total: 14 },
					views: 4,
				},
				tools: [
					'real-testimonials/get-context',
					'real-testimonials/list-testimonials',
					'real-testimonials/get-testimonial',
					'real-testimonials/create-testimonial',
					'real-testimonials/insert-block',
				],
			},
		},
		{
			name: 'real-testimonials/list-testimonials',
			label: __( 'List Testimonials', 'shaped-kit' ),
			summary: __( 'Retrieves paginated testimonials with client names, ratings, company, and approval status.', 'shaped-kit' ),
			prompts: [
				__( 'List the latest 5-star testimonials submitted by customers.', 'shaped-kit' ),
				__( 'Show all pending or draft testimonials that need review.', 'shaped-kit' ),
				__( 'Find any testimonials mentioning "support" or "fast delivery".', 'shaped-kit' ),
			],
			sampleArgs: {
				per_page: 5,
				status: 'publish',
			},
			samplePresets: [
				{
					label: __( 'Latest Published', 'shaped-kit' ),
					args: { per_page: 5, status: 'publish' },
				},
				{
					label: __( '5-Star Reviews', 'shaped-kit' ),
					args: { per_page: 5, rating: 5 },
				},
				{
					label: __( 'Pending / Drafts', 'shaped-kit' ),
					args: { per_page: 5, status: 'draft' },
				},
			],
			expectedOutput: {
				items: [
					{
						id: 14501,
						title: 'Exceptional Quality',
						status: 'publish',
						rating: 5,
						reviewer_name: 'Sarah Connor',
						reviewer_company: 'Cyberdyne Systems',
						date: '2026-10-14 12:00:00',
					},
				],
				total: 12,
				page: 1,
				per_page: 5,
				total_pages: 3,
			},
		},
		{
			name: 'real-testimonials/get-testimonial',
			label: __( 'Get Testimonial', 'shaped-kit' ),
			summary: __( 'Fetches full testimonial record including content, reviewer meta, and categories by ID.', 'shaped-kit' ),
			prompts: [
				__( 'Retrieve testimonial details for ID 42.', 'shaped-kit' ),
				__( 'Show the full review text and reviewer profile for testimonial #105.', 'shaped-kit' ),
				__( 'Inspect the reviewer company and rating for testimonial ID 12.', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 14501,
			},
			expectedOutput: {
				id: 14501,
				title: 'Exceptional Quality',
				content: 'This product transformed our workflow from day one. Highly recommended!',
				status: 'publish',
				rating: 5,
				reviewer_name: 'Sarah Connor',
				reviewer_designation: 'Operations Director',
				reviewer_company: 'Cyberdyne Systems',
				reviewer_location: 'San Francisco, CA',
				date: '2026-10-14 12:00:00',
			},
		},
		{
			name: 'real-testimonials/list-testimonial-views',
			label: __( 'List Testimonial Views', 'shaped-kit' ),
			summary: __( 'Lists saved testimonial templates, layouts, and shortcodes (e.g. grid, carousel, slider).', 'shaped-kit' ),
			prompts: [
				__( 'What testimonial views or shortcodes are created on this site?', 'shaped-kit' ),
				__( 'List all carousel and slider views for customer testimonials.', 'shaped-kit' ),
				__( 'Find the shortcode ID for the homepage testimonial grid.', 'shaped-kit' ),
			],
			sampleArgs: {
				per_page: 10,
			},
			expectedOutput: {
				items: [
					{
						id: 14986,
						title: 'Homepage Reviews Grid',
						layout: 'grid',
						shortcode: '[sp_testimonial id="14986"]',
						status: 'publish',
					},
				],
				total: 4,
				page: 1,
				per_page: 10,
			},
		},
		{
			name: 'real-testimonials/get-testimonial-view',
			label: __( 'Get Testimonial View', 'shaped-kit' ),
			summary: __( 'Fetches layout configuration, styling options, and shortcode attributes for a specific view ID.', 'shaped-kit' ),
			prompts: [
				__( 'Show the layout and configuration settings for testimonial view ID 5.', 'shaped-kit' ),
				__( 'Inspect the layout settings for shortcode [sp_testimonial id="12"].', 'shaped-kit' ),
				__( 'What theme and columns are set on testimonial view #8?', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 14986,
			},
			expectedOutput: {
				id: 14986,
				title: 'Homepage Reviews Grid',
				layout: 'grid',
				theme: 'theme-one',
				columns: 3,
				pagination: true,
				shortcode: '[sp_testimonial id="14986"]',
			},
		},
		{
			name: 'real-testimonials/find-testimonial-blocks',
			label: __( 'Find Testimonial Blocks', 'shaped-kit' ),
			summary: __( 'Scans site pages and posts to locate where testimonial blocks and shortcodes are placed.', 'shaped-kit' ),
			prompts: [
				__( 'Which pages on my site have testimonial blocks or shortcodes embedded?', 'shaped-kit' ),
				__( 'Find where shortcode view [sp_testimonial id="5"] is currently displayed.', 'shaped-kit' ),
				__( 'Audit all pages displaying customer reviews or testimonial sliders.', 'shaped-kit' ),
			],
			sampleArgs: {
				per_page: 10,
			},
			expectedOutput: {
				items: [
					{
						post_id: 42,
						post_title: 'About Us',
						post_type: 'page',
						post_status: 'publish',
						blocks: [ 'sp-testimonial-pro/carousel' ],
					},
				],
				total: 1,
			},
		},
		{
			name: 'real-testimonials/get-settings',
			label: __( 'Get Settings', 'shaped-kit' ),
			summary: __( 'Reads active testimonial layout blocks, page builder integrations, and license status.', 'shaped-kit' ),
			prompts: [
				__( 'Check which page builder integrations are active for Real Testimonials.', 'shaped-kit' ),
				__( 'Is the Gemini AI summary integration configured in Real Testimonials?', 'shaped-kit' ),
				__( 'What block modules are currently enabled in Real Testimonials settings?', 'shaped-kit' ),
			],
			sampleArgs: {},
			expectedOutput: {
				active_blocks: [
					'carousel',
					'slider',
					'grid',
					'testimonial-submission-form',
				],
				integrations: {
					elementor: true,
					gutenberg: true,
				},
				license_status: 'valid',
			},
		},
		{
			name: 'real-testimonials/list-testimonial-categories',
			label: __( 'List Categories (Pro)', 'shaped-kit' ),
			pro: true,
			summary: __( 'Lists testimonial groups and categories with hierarchy and item counts.', 'shaped-kit' ),
			prompts: [
				__( 'List all testimonial categories and how many reviews each has.', 'shaped-kit' ),
				__( 'Show the category group hierarchy for customer testimonials.', 'shaped-kit' ),
				__( 'Find the testimonial category slug for "Enterprise Clients".', 'shaped-kit' ),
			],
			sampleArgs: {
				per_page: 20,
			},
			expectedOutput: {
				items: [
					{
						term_id: 5,
						name: 'Enterprise Clients',
						slug: 'enterprise-clients',
						count: 8,
					},
				],
				total: 1,
			},
		},
		{
			name: 'real-testimonials/list-testimonial-forms',
			label: __( 'List Testimonial Forms (Pro)', 'shaped-kit' ),
			pro: true,
			summary: __( 'Lists frontend testimonial collection forms with shortcode tags and publish status.', 'shaped-kit' ),
			prompts: [
				__( 'List all frontend testimonial collection forms created on this site.', 'shaped-kit' ),
				__( 'What shortcode is used to embed the testimonial submission form?', 'shaped-kit' ),
				__( 'Show all published testimonial collection forms.', 'shaped-kit' ),
			],
			sampleArgs: {
				per_page: 10,
			},
			expectedOutput: {
				items: [
					{
						id: 120,
						title: 'Client Feedback Submission Form',
						shortcode: '[sp_testimonial_form id="120"]',
						status: 'publish',
					},
				],
				total: 1,
			},
		},
		{
			name: 'real-testimonials/get-testimonial-form',
			label: __( 'Get Testimonial Form (Pro)', 'shaped-kit' ),
			pro: true,
			summary: __( 'Retrieves form fields, validation rules, and notification settings for a collection form ID.', 'shaped-kit' ),
			prompts: [
				__( 'Get full settings and field configurations for testimonial form ID 3.', 'shaped-kit' ),
				__( 'What fields are required in the testimonial collection form #7?', 'shaped-kit' ),
				__( 'Inspect notification settings for testimonial form ID 2.', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 120,
			},
			expectedOutput: {
				id: 120,
				title: 'Client Feedback Submission Form',
				fields: [
					'reviewer_name',
					'reviewer_email',
					'rating',
					'review_title',
					'review_content',
				],
				notifications_enabled: true,
			},
		},
		{
			name: 'real-testimonials/get-review-sources-status',
			label: __( 'Review Sources Status (Pro)', 'shaped-kit' ),
			pro: true,
			summary: __( 'Reports frontend submission form channels and review collection configuration.', 'shaped-kit' ),
			prompts: [
				__( 'Are frontend testimonial submission forms active and accepting video reviews?', 'shaped-kit' ),
				__( 'Check the status of testimonial review collection channels.', 'shaped-kit' ),
				__( 'How many frontend testimonial submission forms are published?', 'shaped-kit' ),
			],
			sampleArgs: {},
			expectedOutput: {
				forms_active: true,
				forms_count: 1,
				video_testimonials_enabled: true,
				google_reviews_configured: false,
				trustpilot_configured: false,
			},
		},
		{
			name: 'real-testimonials/create-testimonial',
			label: __( 'Create Testimonial', 'shaped-kit' ),
			summary: __( 'Creates one draft testimonial from the properties a card shows first: title, review text, rating, reviewer name, role, company, location, photo, and groups. The answer lists the keys it saved and the ones it dropped. Email, phone, website, video, logo, social links, and custom fields need the Bulk Import toolset.', 'shaped-kit' ),
			prompts: [
				__( 'Create a new draft testimonial from "Jane Doe" with a 5-star rating.', 'shaped-kit' ),
				__( 'Add a testimonial draft praising our fast onboarding and customer support.', 'shaped-kit' ),
				__( 'Draft a testimonial review for Acme Corp with rating 5.', 'shaped-kit' ),
				__( 'Add a testimonial from Sarah Jenkins, Director at Acme Global in San Francisco, with her photo from the media library and a 4-star rating.', 'shaped-kit' ),
			],
			sampleArgs: {
				title: 'Outstanding customer support and design',
				content: 'The plugin is intuitive, beautifully styled, and our customer reviews look incredible.',
				status: 'draft',
				rating: 5,
				reviewer_name: 'Jane Doe',
				reviewer_designation: 'Head of Product',
				reviewer_company: 'Acme Corp',
				reviewer_location: 'Oslo',
			},
			// The shape of a real run on the local site: the testimonial, `saved` (keys written) and `dropped` (keys not written).
			expectedOutput: {
				id: 15016,
				title: 'Outstanding customer support and design',
				status: 'draft',
				reviewer: {
					name: 'Jane Doe',
					designation: 'Head of Product',
					company: 'Acme Corp',
					location: 'Oslo',
				},
				rating: 5,
				date: '2026-10-15T11:20:00+06:00',
				modified: '2026-10-15T11:20:00+06:00',
				content: 'The plugin is intuitive, beautifully styled, and our customer reviews look incredible.',
				categories: [],
				saved: [
					'title',
					'content',
					'rating',
					'reviewer_name',
					'reviewer_designation',
					'reviewer_company',
					'reviewer_location',
				],
			},
		},
		{
			name: 'real-testimonials/create-testimonials',
			label: __( 'Create Testimonials (Bulk)', 'shaped-kit' ),
			summary: __( 'Creates up to 5 testimonials in one call with every reviewer detail: rating, photo, company logo, video, social links, custom fields, and groups. Drafts by default; a bad item is reported and the rest are still created. Part of the opt-in Bulk Import toolset (off by default, because it adds a large tool to every message). Also use it for one testimonial that needs email, phone, website, country, video, logo, social links, or custom fields.', 'shaped-kit' ),
			prompts: [
				__( 'Add these 5 customer reviews as draft testimonials, with each reviewer\'s name, company, location, and star rating.', 'shaped-kit' ),
				__( 'Import the first 5 testimonials from this spreadsheet and put each one in the Customers group, with their LinkedIn links.', 'shaped-kit' ),
				__( 'Do a dry run first: tell me which of these 5 testimonials would be created and which fields would be dropped.', 'shaped-kit' ),
				__( 'Create these testimonials, set the reviewer photos and company logos from our media library, and keep them as drafts for review.', 'shaped-kit' ),
			],
			sampleArgs: {
				dry_run: true,
				items: [
					{
						title: 'Exceptional quality and support',
						content: 'The team answered every question within the hour.',
						rating: 5,
						reviewer_name: 'Sarah Jenkins',
						reviewer_designation: 'Director of Product',
						reviewer_company: 'Acme Global',
						reviewer_location: 'San Francisco',
						reviewer_country: 'United States',
						reviewer_website: 'https://acme.example',
						social_profiles: [ { network: 'linkedin', url: 'https://linkedin.com/in/sarah' } ],
					},
					{
						title: 'Easy to set up',
						rating: 4,
						reviewer_name: 'Tom Alvarez',
					},
				],
			},
			samplePresets: [
				{
					label: __( 'Dry run (writes nothing)', 'shaped-kit' ),
					args: {
						dry_run: true,
						items: [
							{ title: 'Exceptional quality and support', rating: 5, reviewer_name: 'Sarah Jenkins' },
							{ title: 'Easy to set up', rating: 4, reviewer_name: 'Tom Alvarez' },
						],
					},
				},
				{
					label: __( 'Create two drafts', 'shaped-kit' ),
					args: {
						items: [
							{ title: 'Exceptional quality and support', rating: 5, reviewer_name: 'Sarah Jenkins', reviewer_company: 'Acme Global' },
							{ title: 'Easy to set up', rating: 4, reviewer_name: 'Tom Alvarez' },
						],
					},
				},
			],
			// The shape of a real run on the local site (two items created, one with a dropped key), shortened.
			expectedOutput: {
				dry_run: false,
				requested: 2,
				created: 2,
				failed: 0,
				results: [
					{
						index: 0,
						title: 'Exceptional quality and support',
						ok: true,
						id: 15016,
						status: 'draft',
						edit_url: 'http://localhost:10003/wp-admin/post.php?post=15016&action=edit',
					},
					{
						index: 1,
						title: 'Easy to set up',
						ok: true,
						id: 15017,
						status: 'draft',
						edit_url: 'http://localhost:10003/wp-admin/post.php?post=15017&action=edit',
						dropped: [
							{
								key: 'category_ids',
								reason: 'not_available',
								message: 'Groups are not available on this site.',
							},
						],
					},
				],
			},
		},
		{
			name: 'real-testimonials/update-testimonial',
			label: __( 'Update Testimonial', 'shaped-kit' ),
			summary: __( 'Updates an existing testimonial title, review text, rating, or reviewer profile metadata.', 'shaped-kit' ),
			prompts: [
				__( 'Update testimonial #42 with reviewer designation "VP of Engineering".', 'shaped-kit' ),
				__( 'Change the status of testimonial ID 15 to published.', 'shaped-kit' ),
				__( 'Update the star rating of testimonial #8 to 5.', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 15002,
				title: 'Updated: Outstanding customer support and design',
				rating: 5,
				reviewer_designation: 'VP of Product',
				status: 'publish',
			},
			expectedOutput: {
				status: 'updated',
				id: 15002,
				title: 'Updated: Outstanding customer support and design',
				rating: 5,
				reviewer_designation: 'VP of Product',
				post_status: 'publish',
			},
		},
		{
			name: 'real-testimonials/delete-testimonial',
			label: __( 'Delete Testimonial (Protected)', 'shaped-kit' ),
			summary: __( 'Moves testimonial to trash or permanently deletes it. Permanent deletion requires two-step WriteGuard confirmation.', 'shaped-kit' ),
			prompts: [
				__( 'Permanently delete testimonial #42 with two-step confirmation.', 'shaped-kit' ),
				__( 'Move testimonial ID 99 to trash.', 'shaped-kit' ),
				__( 'Preview permanent deletion of testimonial #14.', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 15002,
				force: false,
			},
			samplePresets: [
				{
					label: __( 'Move to Trash (Safe)', 'shaped-kit' ),
					args: { id: 15002, force: false },
				},
				{
					label: __( 'Permanent Delete (Preview Step)', 'shaped-kit' ),
					args: { id: 15002, force: true },
				},
			],
			expectedOutput: {
				status: 'trashed',
				id: 15002,
				message: 'Testimonial moved to trash.',
			},
		},
		{
			name: 'real-testimonials/get-block-catalog',
			label: __( 'Get Block Catalog (Builder)', 'shaped-kit' ),
			summary: __( 'Returns the settings a testimonial block accepts, with design rules and examples, so your AI client can design it.', 'shaped-kit' ),
			prompts: [
				__( 'Design a dark testimonial carousel with 3 columns and autoplay. Show me the settings first.', 'shaped-kit' ),
				__( 'Which settings can I change on the Real Testimonials grid block?', 'shaped-kit' ),
				__( 'Plan a minimal, light slider for customer reviews using the block catalog.', 'shaped-kit' ),
			],
			sampleArgs: {
				block: 'slider',
			},
			samplePresets: [
				{ label: 'Slider Block', args: { block: 'slider' } },
				{ label: 'Carousel Block', args: { block: 'carousel' } },
				{ label: 'Grid Block', args: { block: 'grid' } },
			],
			expectedOutput: {
				block: 'slider',
				system_prompt: 'You are a Real Testimonials block designer...',
				allowed_attributes: [
					'cardDesign',
					'columns',
					'autoplay',
					'adaptiveHeight',
				],
				layout_defaults: {
					cardDesign: 'design-one',
					navigation: true,
				},
			},
		},
		{
			name: 'real-testimonials/validate-block-attributes',
			label: __( 'Validate Block Attributes (Builder)', 'shaped-kit' ),
			summary: __( 'Checks block settings your AI wrote, lists every dropped setting with its reason, and returns the final settings the block would get.', 'shaped-kit' ),
			prompts: [
				__( 'Check these carousel settings and tell me which ones Real Testimonials would drop.', 'shaped-kit' ),
				__( 'Validate a 4-column grid design with rounded cards and soft shadows.', 'shaped-kit' ),
				__( 'Fix any invalid values in my slider settings and validate them again.', 'shaped-kit' ),
			],
			sampleArgs: {
				block: 'slider',
				attributes: {
					cardDesign: 'design-one',
				},
				prompt: 'Show 3 columns in a responsive slider',
			},
			samplePresets: [
				{
					label: __( 'Dark Theme & Colors', 'shaped-kit' ),
					args: {
						block: 'carousel',
						attributes: {
							cardDesign: 'design-one',
							cardBackground: {
								normal: { style: 'solid', solid: '#0f172a' },
							},
							titleColors: { normal: '#ffffff' },
							testimonialColors: { normal: '#e2e8f0' },
							nameColors: { normal: '#38bdf8' },
							ratingColors: { normal: '#f59e0b' },
						},
						prompt: 'Dark cards with light text and gold stars',
					},
				},
				{
					label: __( 'Responsive 3-Column Slider', 'shaped-kit' ),
					args: {
						block: 'slider',
						attributes: {
							cardDesign: 'design-one',
							columns: 3,
						},
						prompt: 'Show 3 columns in a responsive slider',
					},
				},
			],
			expectedOutput: {
				valid: true,
				block: 'slider',
				attributes: {
					cardDesign: 'design-one',
					columns: 3,
				},
				dropped: [],
				defaults_applied: true,
			},
		},
		{
			name: 'real-testimonials/insert-block',
			label: __( 'Insert Block (Builder, Protected)', 'shaped-kit' ),
			summary: __( 'Saves the built block into a new draft page, or into an existing page after you confirm a preview.', 'shaped-kit' ),
			prompts: [
				__( 'Build a dark testimonial carousel with autoplay and save it as a new draft page called "Customer Love".', 'shaped-kit' ),
				__( 'Add a 3-column testimonial grid at the end of my Testimonials page.', 'shaped-kit' ),
				__( 'Restyle the testimonial slider on my About page with a light, minimal look.', 'shaped-kit' ),
			],
			sampleArgs: {
				block: 'slider',
				attributes: {},
				target: {
					new_draft: 'Customer Reviews',
				},
			},
			samplePresets: [
				{
					label: __( 'New Draft Page (Basic)', 'shaped-kit' ),
					args: {
						block: 'slider',
						attributes: {},
						target: {
							new_draft: 'Customer Reviews',
						},
					},
				},
				{
					label: __( 'Autoplay Carousel', 'shaped-kit' ),
					args: {
						block: 'carousel',
						attributes: {
							autoPlay: true,
							speed: 4000,
							pauseOnHover: true,
							pagination: true,
						},
						target: {
							new_draft: 'Customer Reviews',
						},
					},
				},
				{
					label: __( 'Four-Column Grid', 'shaped-kit' ),
					args: {
						block: 'grid',
						attributes: {
							columns: 4,
							columns_tablet: 2,
							columns_mobile: 1,
							totalTestimonials: 8,
						},
						target: {
							new_draft: 'Customer Reviews',
						},
					},
				},
				{
					label: __( 'Short Review Form', 'shaped-kit' ),
					args: {
						block: 'testimonial-submission-form',
						attributes: {
							showDesignation: false,
							showCompany: false,
						},
						target: {
							new_draft: 'Submit Review',
						},
					},
				},
				{
					label: __( '5-Star Bento', 'shaped-kit' ),
					args: {
						block: 'bento-grid',
						attributes: {
							filterByRating: [ 5 ],
						},
						target: {
							new_draft: 'Customer Reviews',
						},
					},
				},
				{
					label: __( 'Dark Theme & Colors', 'shaped-kit' ),
					args: {
						block: 'carousel',
						attributes: {
							cardDesign: 'design-one',
							cardBackground: {
								normal: { style: 'solid', solid: '#0f172a' },
							},
							titleColors: { normal: '#ffffff' },
							testimonialColors: { normal: '#e2e8f0' },
							nameColors: { normal: '#38bdf8' },
							ratingColors: { normal: '#f59e0b' },
						},
						target: {
							new_draft: 'Dark Theme Showcase',
						},
					},
				},
				{
					label: __( 'With Custom Styling', 'shaped-kit' ),
					args: {
						block: 'slider',
						attributes: {
							cardDesign: 'design-one',
						},
						target: {
							new_draft: 'Customer Reviews',
						},
					},
				},
				{
					label: __( 'Append to Existing Page (Preview)', 'shaped-kit' ),
					args: {
						block: 'slider',
						attributes: {
							cardDesign: 'design-one',
						},
						target: {
							post_id: 42,
							position: 'end',
						},
					},
				},
			],
			expectedOutput: {
				status: 'saved',
				action: 'new_draft',
				post_id: 15001,
				post_status: 'draft',
				title: 'Customer Reviews',
				edit_url: 'http://localhost:10003/wp-admin/post.php?post=15001&action=edit',
				preview_url: 'http://localhost:10003/?page_id=15001&preview=true',
				block: 'slider',
				block_name: 'sp-testimonial-pro/slider',
			},
		},
		{
			name: 'real-testimonials/list-ready-patterns',
			label: __( 'List Ready Patterns (Builder)', 'shaped-kit' ),
			summary: __( 'Lists ready-made testimonial designs from the Ready Patterns library. On Free, Pro designs are shown as needing Pro.', 'shaped-kit' ),
			prompts: [
				__( 'Show me ready-made testimonial carousel designs for a real estate site.', 'shaped-kit' ),
				__( 'Which testimonial grid patterns can I use without Pro?', 'shaped-kit' ),
				__( 'Find a ready testimonial design for an online store.', 'shaped-kit' ),
			],
			sampleArgs: {
				block: 'grid',
				pro: 'include',
				per_page: 5,
			},
			expectedOutput: {
				items: [
					{
						id: 101,
						name: 'Grid – Modern Minimal 01',
						block: 'grid',
						category: 'Grid',
						pro: false,
						insertable: true,
					},
				],
				total: 12,
				page: 1,
				per_page: 5,
			},
		},
		{
			name: 'real-testimonials/insert-ready-pattern',
			label: __( 'Insert Ready Pattern (Builder, Protected)', 'shaped-kit' ),
			summary: __( 'Inserts a ready-made design into a new draft page, or into an existing page after you confirm a preview.', 'shaped-kit' ),
			prompts: [
				__( 'Insert the "Grid – Design 01" pattern into a new draft page called "Reviews".', 'shaped-kit' ),
				__( 'Add a ready-made testimonial carousel to the end of my Home page.', 'shaped-kit' ),
				__( 'Pick a fitting testimonial pattern for my agency site and put it on the About page.', 'shaped-kit' ),
			],
			sampleArgs: {
				pattern_id: 101,
				target: {
					new_draft: 'Our Client Stories',
				},
			},
			expectedOutput: {
				status: 'saved',
				action: 'new_draft',
				post_id: 15003,
				title: 'Our Client Stories',
				pattern_id: 101,
				edit_url: 'http://localhost:10003/wp-admin/post.php?post=15003&action=edit',
				preview_url: 'http://localhost:10003/?page_id=15003&preview=true',
			},
		},
		{
			name: 'real-testimonials/build-testimonial-block',
			label: __( 'Build Testimonial Block (Prompt)', 'shaped-kit' ),
			summary: __( 'Guided prompt template directing an AI assistant to construct and insert a testimonial block.', 'shaped-kit' ),
			prompts: [
				__( 'Build a 3-column carousel testimonial block with customer ratings and dark cards.', 'shaped-kit' ),
				__( 'Create a modern testimonial grid with customer avatars and verified badges.', 'shaped-kit' ),
				__( 'Design a clean testimonial slider section and insert it into a new draft page.', 'shaped-kit' ),
			],
			sampleArgs: {
				request: 'Build a 3-column carousel testimonial block with customer ratings and dark cards.',
			},
			samplePresets: [
				{
					label: __( 'Autoplay Carousel', 'shaped-kit' ),
					args: {
						request: 'Create a testimonial carousel that autoplays every 4 seconds with pause on hover and pagination dots.',
						block: 'carousel',
						page_title: 'Customer Testimonials',
					},
				},
				{
					label: __( 'Four-Column Grid', 'shaped-kit' ),
					args: {
						request: 'Create a testimonial grid with 4 columns on desktop, 2 on tablet and 1 on mobile, showing 8 testimonials.',
						block: 'grid',
						page_title: 'Customer Feedback',
					},
				},
				{
					label: __( 'Short Review Form', 'shaped-kit' ),
					args: {
						request: 'Create a testimonial submission form without the designation and company name fields.',
						block: 'testimonial-submission-form',
						page_title: 'Submit Review',
					},
				},
				{
					label: __( '5-Star Bento', 'shaped-kit' ),
					args: {
						request: 'Create a bento grid of only 5-star testimonials.',
						block: 'bento-grid',
						page_title: 'Top Rated Reviews',
					},
				},
				{
					label: __( 'Autoplay Slider', 'shaped-kit' ),
					args: {
						request: 'Create a testimonial slider that autoplays every 5 seconds with pause on hover and pagination dots.',
						block: 'slider',
						page_title: 'Client Reviews',
					},
				},
				{
					label: __( 'Wall of Love', 'shaped-kit' ),
					args: {
						request: 'Create a masonry wall of love with 4 columns on desktop, 2 on tablet and 1 on mobile, showing 12 testimonials.',
						block: 'masonry',
						page_title: 'Wall of Love',
					},
				},
				{
					label: __( 'Dark Theme & Colors', 'shaped-kit' ),
					args: {
						request: 'Create a testimonial carousel with dark navy cards (#0f172a), white headings, light gray text, sky blue reviewer names, and gold star ratings.',
						block: 'carousel',
						page_title: 'Dark Theme Showcase',
					},
				},
			],
			// The answer of a real `prompts/get` for `sampleArgs` above (Real Testimonials 4.0 on
			// adapter 0.7.0, through tests/phpunit/adapter), the site address swapped for the local
			// one the other samples use. The tool names in it are the wire names.
			expectedOutput: {
				description: 'Guided prompt for building Real Testimonials blocks with AI.',
				messages: [
					{
						role: 'user',
						content: {
							type: 'text',
							text: 'Use the real-testimonials MCP tools on http://localhost:10003.\n\nBuild a Real Testimonials block for this request: "Build a 3-column carousel testimonial block with customer ratings and dark cards."\n\n1. Pick the best block type for the request or inspect options with `real-testimonials-get-block-catalog`.\n\n2. Write the attribute JSON and check it with `real-testimonials-validate-block-attributes`, passing my request as prompt. Fix anything listed under dropped.\n\n3. Save it with `real-testimonials-insert-block` into a new draft page titled "Real Testimonials Preview" using target.new_draft.\n\nIf a ready-made design from `real-testimonials-list-ready-patterns` fits better, insert that with `real-testimonials-insert-ready-pattern` instead.\n\nReply with the preview link.',
						},
					},
				],
			},
		},
	],
	'location-weather': [
		{
			name: 'location-weather/get-context',
			label: __( 'Get Context', 'shaped-kit' ),
			summary: __( 'Reports environment, active API provider, and weather entry counts.', 'shaped-kit' ),
			prompts: [
				__( 'What weather views and templates exist on this site?', 'shaped-kit' ),
				__( 'Check our Location Weather plugin status and capabilities.', 'shaped-kit' ),
				__( 'How many weather widgets are created in Location Weather?', 'shaped-kit' ),
			],
			sampleArgs: {},
			samplePresets: [
				{
					label: __( 'Check Capabilities', 'shaped-kit' ),
					args: {},
				},
			],
			expectedOutput: {
				user_id: 1,
				user_login: 'admin',
				can_access: true,
				edition: 'pro',
				timezone: 'UTC',
				counts: {
					templates: 3,
				},
				tools: [
					'location-weather/get-context',
					'location-weather/list-weather-templates',
					'location-weather/get-weather-template',
					'location-weather/create-view',
				],
			},
		},
		{
			name: 'location-weather/list-weather-templates',
			label: __( 'List Weather Templates', 'shaped-kit' ),
			summary: __( 'Lists saved weather entries with their location, layout view, and shortcodes.', 'shaped-kit' ),
			prompts: [
				__( 'List all configured weather locations and their shortcode IDs.', 'shaped-kit' ),
				__( 'Show weather views displaying London or New York forecasts.', 'shaped-kit' ),
				__( 'Find the weather template ID used for the header widget.', 'shaped-kit' ),
			],
			sampleArgs: {
				per_page: 5,
				sort_by: 'date',
				sort_type: 'DESC',
			},
			samplePresets: [
				{
					label: __( 'Latest 5 Templates', 'shaped-kit' ),
					args: { per_page: 5, sort_by: 'date', sort_type: 'DESC' },
				},
				{
					label: __( 'All Templates', 'shaped-kit' ),
					args: { per_page: 20 },
				},
			],
			expectedOutput: {
				items: [
					{
						id: 201,
						title: 'London Weather Card',
						city_name: 'London, GB',
						units: 'metric',
						layout: 'vertical',
						shortcode: '[location-weather id="201"]',
						status: 'publish',
					},
				],
				total: 3,
				page: 1,
				per_page: 5,
			},
		},
		{
			name: 'location-weather/get-weather-template',
			label: __( 'Get Weather Template', 'shaped-kit' ),
			summary: __( 'Retrieves specific weather entry configuration: units, forecast days, and display options.', 'shaped-kit' ),
			prompts: [
				__( 'Get full layout and forecast settings for weather template #15.', 'shaped-kit' ),
				__( 'Inspect the temperature units and display elements for weather view #3.', 'shaped-kit' ),
				__( 'What location is configured in weather template ID 8?', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 201,
			},
			expectedOutput: {
				id: 201,
				title: 'London Weather Card',
				location_by: 'city_name',
				city_name: 'London, GB',
				units: 'metric',
				layout: 'vertical',
				forecast_days: 5,
				display: {
					temperature: true,
					humidity: true,
					wind: true,
				},
			},
		},
		{
			name: 'location-weather/find-weather-blocks',
			label: __( 'Find Weather Blocks', 'shaped-kit' ),
			summary: __( 'Scans site content to locate where weather blocks or shortcodes are embedded.', 'shaped-kit' ),
			prompts: [
				__( 'Which pages or templates contain Location Weather blocks?', 'shaped-kit' ),
				__( 'Find where weather shortcode [location-weather id="2"] is placed.', 'shaped-kit' ),
				__( 'Audit all pages showing current weather forecast widgets.', 'shaped-kit' ),
			],
			sampleArgs: {
				per_page: 10,
			},
			expectedOutput: {
				items: [
					{
						post_id: 15,
						post_title: 'Contact & Location',
						post_type: 'page',
						post_status: 'publish',
						blocks: [
							'location-weather/weather-block',
						],
					},
				],
				total: 1,
			},
		},
		{
			name: 'location-weather/get-settings',
			label: __( 'Get Settings', 'shaped-kit' ),
			summary: __( 'Reads global weather settings: active API provider, cache duration, and custom CSS flags.', 'shaped-kit' ),
			prompts: [
				__( 'Check the weather data cache expiration time in settings.', 'shaped-kit' ),
				__( 'Which weather API service is active (OpenWeatherMap or WeatherAPI)?', 'shaped-kit' ),
				__( 'Are custom scripts enabled in Location Weather settings?', 'shaped-kit' ),
			],
			sampleArgs: {},
			expectedOutput: {
				api_provider: 'OpenWeatherMap',
				api_key_configured: true,
				cache_time: '180',
				custom_css: false,
			},
		},
		{
			name: 'location-weather/get-cloud-status',
			label: __( 'Get Cloud Status (Pro)', 'shaped-kit' ),
			pro: true,
			summary: __( 'Checks Styble Cloud connection mode, weather AI assistant, and capabilities.', 'shaped-kit' ),
			prompts: [
				__( 'Is Styble Cloud connected for enhanced weather forecasts?', 'shaped-kit' ),
				__( 'Check if the Weather AI Assistant is enabled on this site.', 'shaped-kit' ),
				__( 'What cloud weather capabilities are active?', 'shaped-kit' ),
			],
			sampleArgs: {},
			samplePresets: [
				{
					label: __( 'Check Cloud & AI Assistant', 'shaped-kit' ),
					args: {},
				},
			],
			expectedOutput: {
				cloud_connected: true,
				ai_assistant_enabled: true,
				plan: 'pro',
				capabilities: [
					'hourly_forecast',
					'weather_maps',
					'severe_alerts',
					'ai_summary',
				],
			},
		},
		{
			name: 'location-weather/create-view',
			label: __( 'Create Weather View', 'shaped-kit' ),
			summary: __( 'Creates a new weather template entry with location coordinates or city name, units, and layout.', 'shaped-kit' ),
			prompts: [
				__( 'Create a new weather template for Tokyo with Celsius units and vertical card layout.', 'shaped-kit' ),
				__( 'Add a new draft weather view for London, GB.', 'shaped-kit' ),
				__( 'Create a weather widget showing San Francisco forecast in Fahrenheit.', 'shaped-kit' ),
			],
			sampleArgs: {
				title: 'Tokyo Daily Forecast',
				location_by: 'city_name',
				city_name: 'Tokyo, JP',
				units: 'metric',
				layout: 'vertical',
			},
			samplePresets: [
				{
					label: __( 'Metric (Celsius)', 'shaped-kit' ),
					args: {
						title: 'Tokyo Daily Forecast',
						location_by: 'city_name',
						city_name: 'Tokyo, JP',
						units: 'metric',
						layout: 'vertical',
					},
				},
				{
					label: __( 'Imperial (Fahrenheit)', 'shaped-kit' ),
					args: {
						title: 'New York City Forecast',
						location_by: 'city_name',
						city_name: 'New York, US',
						units: 'imperial',
						layout: 'horizontal',
					},
				},
			],
			expectedOutput: {
				status: 'created',
				id: 202,
				title: 'Tokyo Daily Forecast',
				shortcode: '[location-weather id="202"]',
				units: 'metric',
				city_name: 'Tokyo, JP',
				edit_url: 'http://localhost:10003/wp-admin/post.php?post=202&action=edit',
			},
		},
		{
			name: 'location-weather/update-view-settings',
			label: __( 'Update View Settings', 'shaped-kit' ),
			summary: __( 'Modifies location, display toggles, temperature units, or view layout for an existing template.', 'shaped-kit' ),
			prompts: [
				__( 'Update weather template #12 to display humidity and wind speed.', 'shaped-kit' ),
				__( 'Change the city of weather view 5 to Berlin, DE and switch units to metric.', 'shaped-kit' ),
				__( 'Set layout of weather template #8 to horizontal card.', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 202,
				title: 'Tokyo 5-Day Weather Forecast',
				units: 'metric',
				layout: 'horizontal',
				display: {
					humidity: true,
					wind: true,
				},
			},
			expectedOutput: {
				status: 'updated',
				id: 202,
				title: 'Tokyo 5-Day Weather Forecast',
				units: 'metric',
				layout: 'horizontal',
			},
		},
		{
			name: 'location-weather/duplicate-view',
			label: __( 'Duplicate View', 'shaped-kit' ),
			summary: __( 'Clones an existing weather template into a new draft view.', 'shaped-kit' ),
			prompts: [
				__( 'Duplicate weather template #3 so I can test different display options.', 'shaped-kit' ),
				__( 'Clone the header weather view into a new draft.', 'shaped-kit' ),
				__( 'Make a copy of template 15 titled "Sidebar Weather".', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 202,
				title: 'Tokyo Weather Copy',
			},
			expectedOutput: {
				status: 'duplicated',
				original_id: 202,
				id: 203,
				title: 'Tokyo Weather Copy',
				post_status: 'draft',
				shortcode: '[location-weather id="203"]',
			},
		},
		{
			name: 'location-weather/delete-weather-template',
			label: __( 'Delete Weather Template (Protected)', 'shaped-kit' ),
			summary: __( 'Moves template to trash or permanently deletes it. Permanent deletion requires two-step WriteGuard confirmation.', 'shaped-kit' ),
			prompts: [
				__( 'Permanently delete weather template #9 with two-step confirmation.', 'shaped-kit' ),
				__( 'Move weather template #22 to trash.', 'shaped-kit' ),
				__( 'Preview permanent deletion of weather template ID 7.', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 203,
				force: false,
			},
			samplePresets: [
				{
					label: __( 'Move to Trash (Safe)', 'shaped-kit' ),
					args: { id: 203, force: false },
				},
				{
					label: __( 'Permanent Delete (Preview Step)', 'shaped-kit' ),
					args: { id: 203, force: true },
				},
			],
			expectedOutput: {
				status: 'trashed',
				id: 203,
				message: 'Weather template moved to trash.',
			},
		},
		{
			name: 'location-weather/clear-cache',
			label: __( 'Clear Cache (Protected for Global)', 'shaped-kit' ),
			summary: __( 'Purges cached weather API transients for a single template, or globally with WriteGuard confirmation.', 'shaped-kit' ),
			prompts: [
				__( 'Clear cached weather data for template ID 4 to fetch live conditions.', 'shaped-kit' ),
				__( 'Purge all site-wide weather cache transients with two-step confirmation.', 'shaped-kit' ),
				__( 'Preview clearing global weather cache.', 'shaped-kit' ),
			],
			sampleArgs: {
				id: 201,
				global: false,
			},
			samplePresets: [
				{
					label: __( 'Single Template Cache', 'shaped-kit' ),
					args: { id: 201, global: false },
				},
				{
					label: __( 'Global Site-Wide Cache (Preview Step)', 'shaped-kit' ),
					args: { global: true },
				},
			],
			expectedOutput: {
				status: 'cache_cleared',
				id: 201,
				message: 'Weather cache cleared for template #201.',
			},
		},
	],
};

/**
 * Returns the tool catalog for a product slug, or an empty list if not found.
 * Optionally filters by product edition ('free' hides Pro-only tools).
 *
 * @param {string} slug            Product slug.
 * @param {string} [edition='all'] Product edition ('all', 'free', 'pro').
 * @return {Array<Object>} Tools list.
 */
export const getProductTools = ( slug, edition = 'all' ) => {
	const all = productToolsCatalog[ slug ] || [];
	if ( edition === 'free' ) {
		return all.filter( ( tool ) => ! tool.pro );
	}
	return all;
};
