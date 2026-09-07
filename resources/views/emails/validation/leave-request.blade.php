{{--
    Email informatif : une demande de congé vient d'être soumise au groupe.

    Aucun bouton de validation ni de refus — les décisions se prennent dans
    l'application. Volontairement sobre : les clients de messagerie rendent mal
    les mises en page élaborées, et le lecteur n'a que quelques lignes à lire.
--}}
@component('mail::message')
# Nouvelle demande de congé

**{{ $details['requester_label'] }}** a déposé une demande de congé.

@component('mail::table')
| | |
| :--- | :--- |
@if($details['leave_type'])
| **Type** | {{ $details['leave_type'] }} |
@endif
| **Du** | {{ $details['start_at'] }}@if($details['start_portion']) ({{ $details['start_portion'] }})@endif |
| **Au** | {{ $details['end_at'] }}@if($details['end_portion']) ({{ $details['end_portion'] }})@endif |
@if($details['group_name'])
| **Groupe** | {{ $details['group_name'] }} |
@endif
@endcomponent

@if(filled($details['message']))
**Message du demandeur**

{{ $details['message'] }}
@endif

Cette demande est **en attente de validation** par les valideurs du groupe.

@component('mail::subcopy')
Cet email est informatif : la demande se valide ou se refuse depuis l'application. Vous le recevez parce que votre adresse est configurée sur ce groupe de validation.
@endcomponent
@endcomponent
