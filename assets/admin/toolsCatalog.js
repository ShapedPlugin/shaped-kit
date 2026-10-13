/**
 * Catalog of tools with human descriptions and realistic sample prompts for AI clients.
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
		},
		{
			name: 'real-testimonials/list-testimonial-categories',
			label: __( 'List Categories (Pro)', 'shaped-kit' ),
			summary: __( 'Lists testimonial groups and categories with hierarchy and item counts.', 'shaped-kit' ),
			prompts: [
				__( 'List all testimonial categories and how many reviews each has.', 'shaped-kit' ),
				__( 'Show the category group hierarchy for customer testimonials.', 'shaped-kit' ),
				__( 'Find the testimonial category slug for "Enterprise Clients".', 'shaped-kit' ),
			],
		},
		{
			name: 'real-testimonials/get-review-sources-status',
			label: __( 'Review Sources Status (Pro)', 'shaped-kit' ),
			summary: __( 'Reports frontend submission form channels and review collection configuration.', 'shaped-kit' ),
			prompts: [
				__( 'Are frontend testimonial submission forms active and accepting video reviews?', 'shaped-kit' ),
				__( 'Check the status of testimonial review collection channels.', 'shaped-kit' ),
				__( 'How many frontend testimonial submission forms are published?', 'shaped-kit' ),
			],
		},
		{
			name: 'real-testimonials/create-testimonial',
			label: __( 'Create Testimonial', 'shaped-kit' ),
			summary: __( 'Creates a new draft customer testimonial with reviewer details, company, and star rating.', 'shaped-kit' ),
			prompts: [
				__( 'Create a new draft testimonial from "Jane Doe" with a 5-star rating.', 'shaped-kit' ),
				__( 'Add a testimonial draft praising our fast onboarding and customer support.', 'shaped-kit' ),
				__( 'Draft a testimonial review for Acme Corp with rating 5.', 'shaped-kit' ),
			],
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
		},
		{
			name: 'location-weather/get-cloud-status',
			label: __( 'Get Cloud Status (Pro)', 'shaped-kit' ),
			summary: __( 'Checks Styble Cloud connection mode, weather AI assistant, and capabilities.', 'shaped-kit' ),
			prompts: [
				__( 'Is Styble Cloud connected for enhanced weather forecasts?', 'shaped-kit' ),
				__( 'Check if the Weather AI Assistant is enabled on this site.', 'shaped-kit' ),
				__( 'What cloud weather capabilities are active?', 'shaped-kit' ),
			],
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
		},
	],
};

/**
 * Returns the tool catalog for a product slug, or an empty list if not found.
 *
 * @param {string} slug Product slug.
 * @return {Array<Object>} Tools list.
 */
export const getProductTools = ( slug ) =>
	productToolsCatalog[ slug ] || [];
