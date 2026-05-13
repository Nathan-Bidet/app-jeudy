export default function TitleCaps({ text, className = '' }) {
    return (
        <span className={`title-caps ${className}`}>
            {String(text ?? '')}
        </span>
    );
}
