function duplicateFormSection() {
    // 1. Get the original section to duplicate
    const originalSection = document.querySelector('.form-section');

    // 2. Clone the node with a deep copy (true argument)
    const clonedSection = originalSection.cloneNode(true);

    // Optional: Update IDs to prevent conflicts and maintain accessibility
    // You'll need a counter for unique IDs if you plan to use IDs in backend processing.
    const container = document.getElementById('form-container');
    const sectionCount = container.children.length + 1;
    clonedSection.id = 'form-section-' + sectionCount;

    // 3. Clear input values in the cloned section
    const inputs = clonedSection.querySelectorAll('input');
    inputs.forEach(input => {
        // You might need more specific logic for different input types (radio, checkbox)
        if (input.type === 'text' || input.type === 'textarea') {
            input.value = '';
        } else if (input.type === 'checkbox' || input.type === 'radio') {
            input.checked = false;
        }
    });

    // 4. Append the cloned section to the main container
    container.appendChild(clonedSection);
}