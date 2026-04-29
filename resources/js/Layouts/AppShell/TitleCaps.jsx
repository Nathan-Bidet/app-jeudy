export default function TitleCaps({ text, className = '' }) {
    const words = String(text ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    return (
        <span className={`title-caps ${className}`}>
            {words.map((word, index) => {
                const isSeparator = !/[A-Za-z0-9À-ÿ]/.test(word.charAt(0));

                if (isSeparator) {
                    return (
                        <span key={`${word}-${index}`} className="mr-[0.35em] inline-block last:mr-0">
                            {word}
                        </span>
                    );
                }

                return (
                    <span key={`${word}-${index}`} className="mr-[0.35em] inline-flex items-end last:mr-0">
                        <span className="title-caps__first">{word.charAt(0)}</span>
                        <span className="title-caps__rest">{word.slice(1)}</span>
                    </span>
                );
            })}
        </span>
    );
}
