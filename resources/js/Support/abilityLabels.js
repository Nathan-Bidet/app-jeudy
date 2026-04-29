const ABILITY_LABELS = {
    'dashboard.view': 'Dashboard - Voir',
    'directory.view': 'Annuaire - Voir',
    'a_prevoir.view': 'À Prévoir - Voir',
    'a_prevoir.view.current_week_only': 'À Prévoir - Voir uniquement semaine',
    'a_prevoir.create': 'À Prévoir - Créer',
    'a_prevoir.update': 'À Prévoir - Modifier',
    'a_prevoir.delete': 'À Prévoir - Supprimer',
    'a_prevoir.point': 'À Prévoir - Pointer',
    'a_prevoir.export': 'À Prévoir - Exporter',
    'a_prevoir.sms': 'À Prévoir - SMS',
    'ldt.view': 'Livre du Travail - Voir',
    'ldt.view.all_assignees': 'Livre du Travail - Voir tous les chauffeurs',
    'ldt.view.current_week_only': 'LDT - Voir uniquement semaine',
    'ldt.export': 'Livre du Travail - Exporter',
    'ldt.sms': 'Livre du Travail - SMS',
    'task.data.view': 'Tâches Données - Voir la page',
    'task.data.jeudy.view': 'Tâches Données Personnels Jeudy - Voir',
    'task.data.jeudy.manage': 'Tâches Données Personnels Jeudy - Gérer',
    'task.data.transporters.view': 'Tâches Données Transporteurs - Voir',
    'task.data.transporters.manage': 'Tâches Données Transporteurs - Gérer',
    'task.data.depots.view': 'Tâches Données Dépôts - Voir',
    'task.data.depots.manage': 'Tâches Données Dépôts - Gérer',
    'task.archive.view': 'Tâches Archive - Voir',
    'task.archive.manage': 'Tâches Archive - Gérer',
    'task.formatting.view': 'Mise en forme - Voir',
    'task.formatting.manage': 'Mise en forme - Gérer',
    'calendar.view': 'Calendrier - Voir',
    'calendar.event.manage': 'Calendrier - Gérer les événements',
    'calendar.category.manage': 'Calendrier - Gérer les catégories',
    'calendar.feed.manage': 'Calendrier - Gérer les calendriers publics',
    'heures.view': 'Heures - Voir',
    'heures.create': 'Heures - Créer ses heures',
    'heures.export': 'Heures - Exporter',
    'admin.users.view': 'Administration Utilisateurs - Voir',
    'admin.users.manage': 'Administration Utilisateurs - Gérer',
    'admin.sectors.view': 'Administration Secteurs - Voir',
    'admin.sectors.manage': 'Administration Secteurs - Gérer',
    'admin.access.manage': 'Administration Accès - Gérer les exceptions',
    'admin.logs.view': 'Administration Logs - Voir',
    'admin.entities.view': 'Administration Entités - Voir',
    'admin.entities.manage': 'Administration Entités - Gérer',
};

function titleCaseWord(word) {
    if (!word) return '';

    return word.charAt(0).toUpperCase() + word.slice(1);
}

export function abilityLabel(ability) {
    if (!ability) {
        return 'Permission';
    }

    if (ABILITY_LABELS[ability]) {
        return ABILITY_LABELS[ability];
    }

    const normalized = String(ability)
        .split('.')
        .map((part) => part.replaceAll('_', ' '))
        .map((part) =>
            part
                .split(' ')
                .map((word) => titleCaseWord(word))
                .join(' ')
        )
        .join(' - ');

    return normalized || ability;
}

export default ABILITY_LABELS;
