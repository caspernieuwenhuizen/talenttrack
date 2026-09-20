<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;
use TT\Modules\Comms\Template\AccountMailTemplate;

/**
 * #3794 — the academy asks a family to fill in their own contact
 * details, on a link that opens a short form.
 *
 * Account mail, so outside `TemplateSwitch`: it sends because somebody
 * asked for it about one child, and that is its only condition. It is
 * also the message of last resort — the family it goes to is by
 * definition one the academy currently cannot reach — so it is
 * operational and never withheld.
 *
 * It names the child and the academy. Nothing else about the record
 * travels in it, or on the page it links to.
 *
 * Tokens: {player_name} {academy_name} {form_url} {ttl_days}
 */
final class GuardianContactRequestTemplate extends AbstractTemplate implements AccountMailTemplate {

    public function key(): string { return 'guardian_contact_request'; }
    public function label(): string { return __( 'Guardian contact request', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Please confirm your contact details for {player_name}', 'talenttrack' ),
                __( "Hello,\n\n{academy_name} needs a way to reach you about {player_name} — a cancelled training, a change of time, or an injury.\n\nFill in your details here:\n{form_url}\n\nIt takes a minute, and the link works once. It expires in {ttl_days} days; ask us for a new one if it does.\n\nThank you,\n{academy_name}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Wil je je contactgegevens voor {player_name} doorgeven?', 'talenttrack' ),
                __( "Hallo,\n\n{academy_name} heeft een manier nodig om je te bereiken over {player_name} — een training die vervalt, een tijd die verschuift of een blessure.\n\nVul je gegevens hier in:\n{form_url}\n\nHet kost een minuut en de link werkt één keer. Hij vervalt over {ttl_days} dagen; vraag ons gerust om een nieuwe.\n\nBedankt,\n{academy_name}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
