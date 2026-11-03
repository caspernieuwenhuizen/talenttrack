// @ts-check
/**
 * Rows for the persona dashboards (#4823).
 *
 * CI's wp-env instance has no data, so the head coach's and the scout's
 * dashboard tables rendered no rows and their row links (`.tt-pd-row-link`,
 * the tap target #4817 fixed) were never measured. This makes the smallest
 * set that fills both tables, over REST, and removes it afterwards:
 *
 *   - head coach (`behaviour_pending`): a staff record for the coach
 *     account, a team, a head-coach assignment on it and one active player
 *     with no behaviour rating. The `behaviour_rating` feature is switched
 *     on, and the coach must hold `tt_rate_player_behaviour`.
 *   - scout (`my_recent_prospects`): a prospect the scout logs itself, since
 *     the widget lists prospects with `discovered_by_user_id` = the viewer.
 *
 * Removal (decided on #4823): the player goes archive, then trash, then
 * recycle-bin purge; the team goes through `DELETE /teams/{id}/permanent`;
 * the assignment is deleted. REST has no way to remove a staff record or a
 * prospect, so both carry the `e2e-` prefix and are archived where that is
 * possible. On CI the container dies with the job, so nothing persists.
 */

const { uniqueName } = require( './admin' );

/**
 * One TalentTrack REST call with the page's nonce. Like `rest()` in
 * `match-prep.js`, but a GET or a DELETE carries no body, which `fetch()`
 * refuses for GET.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} method
 * @param {string} path
 * @param {object|null} body
 * @returns {Promise<{status:number, json:any}>}
 */
function rest( page, method, path, body ) {
	return page.evaluate( async ( { method, path, body } ) => {
		const cfg = window.TT || {};
		const res = await fetch( cfg.rest_url + path, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
			body: body === null ? undefined : JSON.stringify( body ),
		} );
		let json = null;
		try { json = await res.json(); } catch ( _e ) { json = null; }
		return { status: res.status, json };
	}, { method, path, body } );
}

/** Every name this fixture writes starts with it. */
const PREFIX = 'e2e-';

/**
 * A dashboard page signed in as `state`, carrying `window.TT` for REST.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string|undefined} baseURL
 * @param {string} state storage state file
 */
async function restContext( browser, baseURL, state ) {
	const context = await browser.newContext( { storageState: state, baseURL } );
	const page = await context.newPage();
	await page.goto( '/' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const ready = await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) );
	return { context, page, ready };
}

/** @param {{ status: number, json: any }} res */
function dataId( res ) {
	return res.json && res.json.data && res.json.data.id ? Number( res.json.data.id ) : 0;
}

/**
 * Make the rows. Returns what was made, so `removeFixture()` can take it
 * away, and every refusal by name, so a failed setup says which call failed.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string|undefined} baseURL
 * @param {{ coachUserId: number, coachState: string, scoutState: string }} who
 */
async function seedFixture( browser, baseURL, who ) {
	/** @type {{ teamId: number, playerId: number, personId: number, personReused: boolean, assignmentId: number, prospectId: number, featureOn: boolean, coachMayRate: boolean, errors: string[], scoutErrors: string[] }} */
	const made = { teamId: 0, playerId: 0, personId: 0, personReused: false, assignmentId: 0, prospectId: 0, featureOn: false, coachMayRate: false, errors: [], scoutErrors: [] };
	try {
		await seedSteps( browser, baseURL, who, made );
	} catch ( e ) {
		// A throw partway leaves whatever was made already: take it away
		// before failing, since the caller never gets `made` to do it.
		await removeFixture( browser, baseURL, made ).catch( () => [] );
		throw e;
	}
	return made;
}

/**
 * The calls `seedFixture()` makes, recording each id in `made` as soon as
 * it exists so a throw later on can still remove it.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string|undefined} baseURL
 * @param {{ coachUserId: number, coachState: string, scoutState: string }} who
 * @param {Awaited<ReturnType<typeof seedFixture>>} made
 */
async function seedSteps( browser, baseURL, who, made ) {
	const admin =await restContext( browser, baseURL, 'tests/e2e/.auth/admin.json' );
	try {
		if ( ! admin.ready ) {
			made.errors.push( 'the admin dashboard carried no REST config' );
			return;
		}
		const page = admin.page;

		// Behaviour capture is a sub-feature; with it off the coach widget
		// returns no rows whatever the data.
		const feature = await rest( page, 'POST', 'features', { key: 'behaviour_rating', enabled: true } );
		made.featureOn = !! ( feature.json && feature.json.enabled === true );
		if ( ! made.featureOn ) made.errors.push( `behaviour_rating not switched on: HTTP ${ feature.status }` );

		const roles = await rest( page, 'GET', 'functional-roles', null );
		const rows = roles.json && roles.json.data && Array.isArray( roles.json.data.rows ) ? roles.json.data.rows : [];
		const headCoach = rows.find( ( r ) => r.role_key === 'head_coach' );
		if ( ! headCoach ) {
			made.errors.push( `no head_coach functional role: HTTP ${ roles.status }` );
			return;
		}

		const person = await rest( page, 'POST', 'people', {
			first_name: PREFIX + 'Coach',
			last_name: uniqueName( PREFIX + 'gate' ),
			role_type: 'head_coach',
			wp_user_id: who.coachUserId,
			status: 'active',
		} );
		made.personId = dataId( person );
		// The coach account already has an active staff record (the 409 of
		// #4019's one-account-one-person rule names it): use that one.
		if ( ! made.personId && person.status === 409 ) {
			const err = person.json && Array.isArray( person.json.errors ) ? person.json.errors[ 0 ] : null;
			made.personId = err && err.details && err.details.person_id ? Number( err.details.person_id ) : 0;
			made.personReused = made.personId > 0;
		}
		if ( ! made.personId ) made.errors.push( `staff record refused: HTTP ${ person.status }` );

		const team = await rest( page, 'POST', 'teams', { name: uniqueName( PREFIX + 'team' ) } );
		made.teamId = dataId( team );
		if ( ! made.teamId ) made.errors.push( `team refused: HTTP ${ team.status }` );

		if ( made.personId && made.teamId ) {
			const assigned = await rest( page, 'POST', 'functional-roles/assignments', {
				team_id: made.teamId,
				person_id: made.personId,
				functional_role_id: Number( headCoach.id ),
			} );
			if ( assigned.status !== 200 ) made.errors.push( `head-coach assignment refused: HTTP ${ assigned.status }` );
			const listed = await rest( page, 'GET', `functional-roles/assignments?team_id=${ made.teamId }`, null );
			const assignment = ( listed.json && listed.json.data && listed.json.data.rows ? listed.json.data.rows : [] )
				.find( ( r ) => Number( r.person_id ) === made.personId );
			made.assignmentId = assignment ? Number( assignment.id ) : 0;
		}

		if ( made.teamId ) {
			const player = await rest( page, 'POST', 'players', {
				first_name: PREFIX + 'Speler',
				last_name: uniqueName( PREFIX + 'gate' ),
				team_id: made.teamId,
				status: 'active',
			} );
			made.playerId = dataId( player );
			if ( ! made.playerId ) made.errors.push( `player refused: HTTP ${ player.status }` );
		}

		// Creating the team queues TeamStaffPrompt's "no head coach yet"
		// flash for the admin, shown on its next page: whichever surface the
		// walk opens first. Load one page so the walk starts with none.
		await page.goto( '/' );
		await page.waitForLoadState( 'load', { timeout: 30000 } );
	} finally {
		await admin.context.close();
	}

	// The coach must be allowed to rate this player, or `behaviour_pending`
	// stays empty. An out-of-range rating gets past the permission check and
	// is refused before anything is written: 400 means allowed, 403 not.
	if ( made.playerId ) {
		const coach = await restContext( browser, baseURL, who.coachState );
		try {
			const probe = await rest( coach.page, 'POST', `players/${ made.playerId }/behaviour-ratings`, { rating: -1 } );
			made.coachMayRate = probe.status === 400;
			if ( ! made.coachMayRate ) made.errors.push( `the coach may not rate behaviour: HTTP ${ probe.status } (400 expected)` );
		} finally {
			await coach.context.close();
		}
	}

	const scout = await restContext( browser, baseURL, who.scoutState );
	try {
		const prospect = await rest( scout.page, 'POST', 'prospects', {
			first_name: PREFIX + 'Prospect',
			last_name: uniqueName( PREFIX + 'gate' ),
		} );
		made.prospectId = prospect.json && prospect.json.data && prospect.json.data.prospect_id ? Number( prospect.json.data.prospect_id ) : 0;
		if ( ! made.prospectId ) made.scoutErrors.push( `prospect refused: HTTP ${ prospect.status }` );
	} finally {
		await scout.context.close();
	}
}

/**
 * Take the rows away, and say for each what is left of it. A record that
 * REST can remove must answer 404 afterwards; the two it cannot remove must
 * carry the prefix.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string|undefined} baseURL
 * @param {Awaited<ReturnType<typeof seedFixture>>} made
 * @returns {Promise<string[]>} what is not as it should be
 */
async function removeFixture( browser, baseURL, made ) {
	/** @type {string[]} */
	const problems = [];
	const admin = await restContext( browser, baseURL, 'tests/e2e/.auth/admin.json' );
	const page = admin.page;
	try {
		if ( made.assignmentId ) {
			const res = await rest( page, 'DELETE', `functional-roles/assignments/${ made.assignmentId }`, null );
			if ( res.status !== 200 ) problems.push( `assignment ${ made.assignmentId } not deleted: HTTP ${ res.status }` );
			// Asked by team while the team still exists: the list joins
			// teams, so after the team delete it could not show the row.
			if ( made.teamId ) {
				const listed = await rest( page, 'GET', `functional-roles/assignments?team_id=${ made.teamId }`, null );
				const left = ( listed.json && listed.json.data && listed.json.data.rows ? listed.json.data.rows : [] )
					.filter( ( r ) => Number( r.id ) === made.assignmentId );
				if ( listed.status !== 200 ) problems.push( `assignments of team ${ made.teamId } not listed: HTTP ${ listed.status }` );
				if ( left.length ) problems.push( `assignment ${ made.assignmentId } is still listed` );
			}
		}

		if ( made.playerId ) {
			await rest( page, 'DELETE', `players/${ made.playerId }`, null );
			await rest( page, 'POST', `players/${ made.playerId }/trash`, null );
			const purged = await rest( page, 'DELETE', `recycle-bin/player/${ made.playerId }`, null );
			if ( purged.status !== 200 ) problems.push( `player ${ made.playerId } not purged: HTTP ${ purged.status }` );
			const after = await rest( page, 'GET', `players/${ made.playerId }`, null );
			if ( after.status !== 404 ) problems.push( `player ${ made.playerId } still answers HTTP ${ after.status } after the purge` );
		}

		if ( made.teamId ) {
			await rest( page, 'DELETE', `teams/${ made.teamId }`, null );
			const gone = await rest( page, 'DELETE', `teams/${ made.teamId }/permanent`, null );
			if ( gone.status !== 200 ) problems.push( `team ${ made.teamId } not deleted: HTTP ${ gone.status }` );
			const after = await rest( page, 'GET', `teams/${ made.teamId }`, null );
			if ( after.status !== 404 ) problems.push( `team ${ made.teamId } still answers HTTP ${ after.status } after the delete` );
		}

		// REST cannot remove a staff record: it is archived. One the fixture
		// made carries the prefix; one it reused is the coach account's own,
		// which goes with that account.
		if ( made.personId ) {
			const archived = await rest( page, 'DELETE', `people/${ made.personId }`, null );
			if ( archived.status !== 200 ) problems.push( `staff record ${ made.personId } not archived: HTTP ${ archived.status }` );
			if ( ! made.personReused ) {
				const after = await rest( page, 'GET', `people/${ made.personId }`, null );
				const name = after.json && after.json.data ? String( after.json.data.first_name || '' ) : '';
				if ( after.status === 200 && ! name.startsWith( PREFIX ) ) problems.push( `staff record ${ made.personId } is left without the ${ PREFIX } prefix` );
			}
		}

		// Nor a prospect: it is left, under the prefix.
		if ( made.prospectId ) {
			const after = await rest( page, 'GET', `prospects/${ made.prospectId }`, null );
			const row = after.json && after.json.data ? after.json.data.prospect : null;
			const name = row ? String( row.first_name || '' ) : '';
			if ( after.status === 200 && ! name.startsWith( PREFIX ) ) problems.push( `prospect ${ made.prospectId } is left without the ${ PREFIX } prefix` );
		}
	} finally {
		await admin.context.close();
	}
	return problems;
}

module.exports = { seedFixture, removeFixture, PREFIX };
