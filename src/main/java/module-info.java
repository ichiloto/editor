module com.atatusoft.games.ichilotoeditor {
    requires javafx.controls;
    requires javafx.fxml;

    requires org.controlsfx.controls;
    requires org.kordamp.bootstrapfx.core;

    opens com.atatusoft.games.ichilotoeditor to javafx.fxml;
    exports com.atatusoft.games.ichilotoeditor;
}